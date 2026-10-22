<?php

namespace App\Http\Controllers;

use App\Models\BillingInfo;
use App\Models\CollectionSummary;
use App\Models\CustomersAddress;
use App\Models\CustomersInfo;
use App\Models\OfficialInfo;
use App\Models\PaymentSummary;
use App\Models\PPPSecrets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelReader;
use Throwable;

class CustomerBulkImportController extends Controller
{
    private const FIELDS = [
        'username' => 'PPPoE Username',
        'customer_unique_id' => 'Customer Unique ID',
        'customer_name' => 'Customer Name',
        'mobile' => 'Mobile',
        'alternative_mobile' => 'Alternative Mobile',
        'address' => 'Address',
    ];

    /** Download a blank template with the exact headers accepted by this importer. */
    public function downloadSample(string $format)
    {
        $format = strtolower($format);
        abort_unless(in_array($format, ['csv', 'xlsx', 'xls'], true), 404);

        $headers = array_values(self::FIELDS);
        $filename = 'customer-bulk-import-sample.'.$format;

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($headers) {
                $output = fopen('php://output', 'wb');
                fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel compatibility.
                fputcsv($output, $headers);
                fclose($output);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Customer Import');
        foreach ($headers as $index => $header) {
            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($column.'1', $header);
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        $temporaryPath = tempnam(sys_get_temp_dir(), 'customer-import-template-');
        if ($temporaryPath === false) {
            throw new \RuntimeException('Could not create a temporary customer import template.');
        }

        try {
            $writer = $format === 'xlsx'
                ? new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet)
                : new \PhpOffice\PhpSpreadsheet\Writer\Xls($spreadsheet);
            $writer->save($temporaryPath);
        } catch (\Throwable $exception) {
            @unlink($temporaryPath);
            throw $exception;
        }

        $contentType = $format === 'xlsx'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'application/vnd.ms-excel';

        return response()->download($temporaryPath, $filename, ['Content-Type' => $contentType])
            ->deleteFileAfterSend(true);
    }

    public function index(Request $request)
    {
        if ($request->boolean('reset')) {
            $oldPath = session('customer_bulk_import_path');
            if ($oldPath) Storage::disk('local')->delete($oldPath);
            session()->forget([
                'customer_bulk_import_path','customer_bulk_import_filename','customer_bulk_import_headers',
                'customer_bulk_import_mapping','customer_bulk_import_preview_rows','customer_bulk_import_summary',
                'customer_bulk_import_issues','customer_bulk_import_validated',
            ]);
        }
        $headers = session('customer_bulk_import_headers', []);
        $mapping = session('customer_bulk_import_mapping', []);
        $summary = session('customer_bulk_import_summary');
        $issues = session('customer_bulk_import_issues', []);
        $previewRows = session('customer_bulk_import_preview_rows', []);
        $filename = session('customer_bulk_import_filename');
        $validated = (bool) session('customer_bulk_import_validated', false);

        return view('xlink.customer-bulk-import', compact('headers','mapping','summary','issues','previewRows','filename','validated'))
            ->with('fields', self::FIELDS);
    }

    public function preview(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240']);
        $file = $request->file('file');
        $oldPath = session('customer_bulk_import_path');
        if ($oldPath) Storage::disk('local')->delete($oldPath);
        $storedPath = Storage::disk('local')->putFileAs(
            'customer-imports',
            $file,
            Str::uuid().'.'.$file->getClientOriginalExtension()
        );

        try {
            $rows = $this->readRows(Storage::disk('local')->path($storedPath));
            if ($rows->isEmpty()) {
                Storage::disk('local')->delete($storedPath);
                return back()->withErrors(['file' => 'The uploaded file has no data rows.']);
            }

            $headers = array_values(array_map('strval', array_keys((array) $rows->first())));
            if (count($headers) < 2) {
                Storage::disk('local')->delete($storedPath);
                return back()->withErrors(['file' => 'The file needs a header row with separate columns.']);
            }

            $mapping = $this->suggestMapping($headers);
            session([
                'customer_bulk_import_path' => $storedPath,
                'customer_bulk_import_filename' => $file->getClientOriginalName(),
                'customer_bulk_import_headers' => $headers,
                'customer_bulk_import_mapping' => $mapping,
                'customer_bulk_import_preview_rows' => $rows->take(8)->map(fn($r) => (array) $r)->values()->all(),
                'customer_bulk_import_summary' => null,
                'customer_bulk_import_issues' => [],
                'customer_bulk_import_validated' => false,
            ]);

            return redirect()->route('customer-bulk-import')->with('bulk_import_message', 'File uploaded. Confirm column mapping, then run pre-flight validation before importing.');
        } catch (Throwable $e) {
            Storage::disk('local')->delete($storedPath);
            report($e);
            return back()->withErrors(['file' => 'Could not read this file. Verify that it is a valid CSV or Excel workbook.']);
        }
    }

    public function validateMapping(Request $request)
    {
        $path = session('customer_bulk_import_path');
        abort_unless($path && Storage::disk('local')->exists($path), 422, 'Upload the customer file again; the temporary file has expired.');

        $headers = session('customer_bulk_import_headers', []);
        $rules = [
            'mapping.username' => ['required','string',\Illuminate\Validation\Rule::in($headers)],
            'mapping.customer_unique_id' => ['required','string',\Illuminate\Validation\Rule::in($headers)],
            'mapping.customer_name' => ['nullable','string',\Illuminate\Validation\Rule::in($headers)],
            'mapping.mobile' => ['nullable','string',\Illuminate\Validation\Rule::in($headers)],
            'mapping.alternative_mobile' => ['nullable','string',\Illuminate\Validation\Rule::in($headers)],
            'mapping.address' => ['nullable','string',\Illuminate\Validation\Rule::in($headers)],
        ];
        $data = $request->validate($rules);
        $mapping = array_filter($data['mapping'], fn($value) => $value !== null && $value !== '');
        abort_if($mapping['username'] === $mapping['customer_unique_id'], 422, 'PPPoE Username and Customer Unique ID must map to different columns.');

        $rows = $this->readRows(Storage::disk('local')->path($path));
        [$summary,$issues] = $this->analyzeRows($rows, $mapping);
        session([
            'customer_bulk_import_mapping' => $mapping,
            'customer_bulk_import_summary' => $summary,
            'customer_bulk_import_issues' => array_slice($issues, 0, 100),
            'customer_bulk_import_validated' => true,
        ]);

        return redirect()->route('customer-bulk-import')->with('bulk_import_message', 'Pre-flight validation complete. Only rows marked valid will be imported.');
    }

    public function commit(Request $request)
    {
        $path = session('customer_bulk_import_path');
        $mapping = session('customer_bulk_import_mapping', []);
        abort_unless(session('customer_bulk_import_validated') && $path && Storage::disk('local')->exists($path), 422, 'Upload and validate the file before importing.');

        $rows = $this->readRows(Storage::disk('local')->path($path));
        [$summary,$issues,$validRows] = $this->analyzeRows($rows, $mapping, true);
        abort_if(count($validRows) === 0, 422, 'There are no valid rows to import. Fix the errors and validate again.');

        $created = 0;
        $updated = 0;
        DB::transaction(function () use ($validRows, &$created, &$updated) {
            foreach ($validRows as $item) {
                $row = $item['row'];
                $username = $item['username'];
                $cid = $item['cid'];
                $ppp = $item['ppp'];
                $customer = CustomersInfo::where('ppp_user_id', $ppp->id)->first();

                $name = trim((string)($row['customer_name'] ?? ''));
                $mobile = trim((string)($row['mobile'] ?? ''));
                $altMobile = trim((string)($row['alternative_mobile'] ?? ''));
                $address = trim((string)($row['address'] ?? ''));

                if ($customer) {
                    $oldCid = trim((string)$customer->customer_unique_id);
                    $customer->customer_unique_id = $cid;
                    if ($name !== '') $customer->customer_name = $name;
                    if ($mobile !== '') $customer->mobile = $mobile;
                    if ($altMobile !== '') $customer->alternative_mobile = $altMobile;
                    if ($address !== '') $customer->address = $address;
                    if ($customer->status === 'pending') $customer->status = 'active';
                    $customer->save();

                    if ($oldCid !== '' && $oldCid !== $cid) {
                        BillingInfo::where('customer_bill_unique_id',$oldCid)->update(['customer_bill_unique_id'=>$cid]);
                        OfficialInfo::where('customer_office_unique_id',$oldCid)->update(['customer_office_unique_id'=>$cid]);
                        CustomersAddress::where('customer_address_unique_id',$oldCid)->update(['customer_address_unique_id'=>$cid]);
                        PaymentSummary::where('customer_payment_unique_id',$oldCid)->update(['customer_payment_unique_id'=>$cid]);
                        CollectionSummary::where('customer_collection_unique_id',$oldCid)->update(['customer_collection_unique_id'=>$cid]);
                    }
                    $updated++;
                } else {
                    CustomersInfo::create([
                        'customer_unique_id'=>$cid,
                        'ppp_user_id'=>$ppp->id,
                        'customer_name'=>$name !== '' ? $name : $username,
                        'address'=>$address !== '' ? $address : null,
                        'mobile'=>$mobile !== '' ? $mobile : null,
                        'alternative_mobile'=>$altMobile !== '' ? $altMobile : null,
                        'status'=>'active',
                        'connection_date'=>now(),
                    ]);
                    BillingInfo::create(['customer_bill_unique_id'=>$cid,'billing_type'=>'prepaid','auto_disable_date'=>now()]);
                    OfficialInfo::create(['customer_office_unique_id'=>$cid,'billing_type'=>'prepaid','connection_type'=>'fiber','connectivity_type'=>'shared','client_type'=>'home']);
                    $created++;
                }
            }
        });

        Storage::disk('local')->delete($path);
        session()->forget([
            'customer_bulk_import_path','customer_bulk_import_filename','customer_bulk_import_headers',
            'customer_bulk_import_mapping','customer_bulk_import_preview_rows','customer_bulk_import_summary',
            'customer_bulk_import_issues','customer_bulk_import_validated',
        ]);

        return redirect()->route('customer-bulk-import')->with('bulk_import_success', "Import complete: {$created} customers created, {$updated} customers updated. No Customer ID was auto-generated.");
    }

    private function readRows(string $path)
    {
        return SimpleExcelReader::create($path)->getRows()->take(10000)->values();
    }

    private function suggestMapping(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $header) $normalized[$this->normalize($header)] = $header;
        $aliases = [
            'username'=>['pppoeusername','pppoeuser','username','user'],
            'customer_unique_id'=>['customeruniqueid','uniqueid','customerid','clientid','cid','id'],
            'customer_name'=>['customername','name','fullname','clientname'],
            'mobile'=>['mobile','phone','mobileno','phoneno','contact','contactno'],
            'alternative_mobile'=>['alternativemobile','altmobile','alternativephone','altphone'],
            'address'=>['address','customeraddress','location','presentaddress'],
        ];
        $mapping=[];
        foreach($aliases as $field=>$keys) {
            $mapping[$field]='';
            foreach($keys as $key) if(isset($normalized[$key])) {$mapping[$field]=$normalized[$key];break;}
        }
        return $mapping;
    }

    private function analyzeRows($rows, array $mapping, bool $includeValidRows = false): array
    {
        $issues=[]; $validRows=[]; $seenCids=[]; $seenUsers=[]; $usernames=[];
        foreach($rows as $index=>$row) {
            $row=(array)$row; $username=trim((string)($row[$mapping['username']??'']??'')); $cid=trim((string)($row[$mapping['customer_unique_id']??'']??''));
            $name=trim((string)($row[$mapping['customer_name']??'']??'')); $mobile=trim((string)($row[$mapping['mobile']??'']??''));
            $alt=trim((string)($row[$mapping['alternative_mobile']??'']??'')); $address=trim((string)($row[$mapping['address']??'']??''));
            $rowNumber=$index+2;
            if($username===''){$issues[]=['row'=>$rowNumber,'identifier'=>'—','reason'=>'Missing PPPoE username'];continue;}
            if($cid===''){$issues[]=['row'=>$rowNumber,'identifier'=>$username,'reason'=>'Missing Customer Unique ID (manual entry required)'];continue;}
            if(isset($seenUsers[$username])){$issues[]=['row'=>$rowNumber,'identifier'=>$username,'reason'=>'Duplicate PPPoE username in this file'];continue;}
            if(isset($seenCids[$cid])){$issues[]=['row'=>$rowNumber,'identifier'=>$username,'reason'=>'Duplicate Customer Unique ID in this file'];continue;}
            $seenUsers[$username]=true; $seenCids[$cid]=true; $usernames[$username]=true;
            $row['_mapped']=['customer_name'=>$name,'mobile'=>$mobile,'alternative_mobile'=>$alt,'address'=>$address];
            $row['_username']=$username; $row['_cid']=$cid;
            $validRows[]=['row'=>$row,'username'=>$username,'cid'=>$cid,'row_number'=>$rowNumber];
        }
        $secrets=PPPSecrets::whereIn('username',array_keys($usernames))->get()->keyBy('username');
        $pppIds=$secrets->pluck('id')->all();
        $customersByPpp=CustomersInfo::whereIn('ppp_user_id',$pppIds)->get()->keyBy('ppp_user_id');
        $cids=array_column($validRows,'cid');
        $existingByCid=CustomersInfo::whereIn('customer_unique_id',$cids)->get()->keyBy('customer_unique_id');
        $filtered=[];
        foreach($validRows as $item) {
            $ppp=$secrets->get($item['username']);
            if(!$ppp){$issues[]=['row'=>$item['row_number'],'identifier'=>$item['username'],'reason'=>'PPPoE username was not found in synced MikroTik secrets'];continue;}
            $existing=$existingByCid->get($item['cid']);
            $current=$customersByPpp->get($ppp->id);
            if($existing && (!$current || $existing->id !== $current->id)){$issues[]=['row'=>$item['row_number'],'identifier'=>$item['username'],'reason'=>'Customer Unique ID is already assigned to another customer'];continue;}
            $item['ppp']=$ppp;
            $item['row']['customer_name']=$item['row']['_mapped']['customer_name'];
            $item['row']['mobile']=$item['row']['_mapped']['mobile'];
            $item['row']['alternative_mobile']=$item['row']['_mapped']['alternative_mobile'];
            $item['row']['address']=$item['row']['_mapped']['address'];
            $filtered[]=$item;
        }
        $summary=['total'=>$rows->count(),'valid'=>count($filtered),'invalid'=>count($issues),'created'=>count(array_filter($filtered,fn($i)=>!$customersByPpp->has($i['ppp']->id))),'updated'=>count(array_filter($filtered,fn($i)=>$customersByPpp->has($i['ppp']->id)))];
        return $includeValidRows ? [$summary,$issues,$filtered] : [$summary,$issues];
    }

    private function normalize(string $header): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i','',$header));
    }
}
