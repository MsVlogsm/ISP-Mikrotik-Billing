<?php

namespace App\Http\Controllers;

use Intervention\Image\Laravel\Facades\Image;
use Illuminate\Support\Facades\Storage;

class AvatarController extends Controller
{
    public function generateAvatar($name)
    {
        $name = trim((string) $name);
        $fileName = 'images/avatars/'.md5($name ?: 'unknown').'.png';
        $disk = Storage::disk('public');

        // Store generated UI avatars on Laravel's writable public disk, not
        // under public/images (owned by root on Production and intentionally read-only).
        if ($disk->exists($fileName)) {
            return $disk->url($fileName);
        }

        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $initials = collect($parts)
            ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->take(3)
            ->join('');

        if ($initials === '') {
            $initials = '?';
        }

        $image = Image::create(500, 500)->fill('#cef2ef')
            ->text($initials, 250, 250, function ($font) {
                $font->file(public_path('webfonts/Tinos-Regular.ttf'));
                $font->size(230);
                $font->color('#009e5c');
                $font->stroke('#cef2ef', 1);
                $font->align('center');
                $font->valign('middle');
                $font->lineHeight(1.6);
                $font->angle(0);
                $font->wrap(250);
            });

        $directory = dirname($fileName);
        if (! $disk->exists($directory)) {
            $disk->makeDirectory($directory);
        }

        $image->save($disk->path($fileName));

        return $disk->url($fileName);
    }
}
