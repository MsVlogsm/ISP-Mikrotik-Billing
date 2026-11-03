import {
    Map as MapLibreMap,
    NavigationControl,
    FullscreenControl,
    ScaleControl,
    Popup,
    LngLatBounds,
    setWorkerUrl,
} from 'maplibre-gl';
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';
import 'maplibre-gl/dist/maplibre-gl.css';

setWorkerUrl(workerUrl);

const STREET_STYLE_URL = 'https://tiles.openfreemap.org/styles/liberty';
const DARK_STYLE_URL = 'https://tiles.openfreemap.org/styles/fiord';
const ESRI_IMAGERY_URL =
    'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';
const ESRI_TRANSPORT_URL =
    'https://services.arcgisonline.com/arcgis/rest/services/Reference/World_Transportation/MapServer/tile/{z}/{y}/{x}';
const DEFAULT_CENTER = [90.4125, 23.8103];

const COLORS = {
    router: '#2563eb',
    olt: '#7c3aed',
    onu: '#0891b2',
    'wifi-router': '#16a34a',
    'access-point': '#ea580c',
    switch: '#64748b',
    customer: '#64748b',
    splitter: '#d97706',
    olt_pon: '#7c3aed',
    odf: '#0f766e',
    rack: '#475569',
    pop: '#0e7490',
    fiber_segment: '#a16207',
    cabinet: '#b45309',
    pole: '#ca8a04',
    splice: '#c2410c',
    server: '#4f46e5',
};
const KINDS = {
    router: 'MikroTik Router',
    olt: 'OLT',
    onu: 'ONU',
    'wifi-router': 'WiFi Router',
    'access-point': 'Access Point',
    switch: 'Switch',
    customer: 'Customer',
    splitter: 'Fiber Splitter',
    olt_pon: 'OLT PON Port',
    odf: 'ODF',
    rack: 'Rack',
    pop: 'POP',
    fiber_segment: 'Fiber Segment',
    cabinet: 'Street Cabinet',
    pole: 'Utility Pole',
    splice: 'Splice Closure',
    server: 'Server / Headend',
};

const escapeHtml = (value) =>
    String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    }[character]));

const asFeatureCollection = (features) => ({
    type: 'FeatureCollection',
    features,
});

const pointFeature = (node) => {
    const key = node.map_key || `${node.kind === 'router' ? 'router:' : 'device:'}${node.id}`;
    return {
        type: 'Feature',
        id: key,
        properties: {
            key,
            kind: node.kind || 'device',
            asset_type: node.asset_type || node.kind || 'device',
            notes: node.notes || '',
            label: node.label || node.id || 'Device',
            short_label: node.label || node.id || 'Device',
            status: node.status || 'unknown',
            ip: node.ip || '',
            location: node.location || '',
            router: node.router || '',
            customer_uid: node.customer_uid || '',
            customer_url: node.customer_url || '',
            account_status: node.account_status || '',
            latitude: Number(node.lat),
            longitude: Number(node.lng),
            vendor: node.vendor || '',
            model: node.model || '',
            firmware: node.firmware || '',
            serial_no: node.serial_no || '',
            health_status: node.health_status || '',
            last_checked_at: node.last_checked_at || '',
            last_latency_ms: node.last_latency_ms ?? null,
            onu_total: node.onu_total ?? null,
            onu_online: node.onu_online ?? null,
            rx_power: node.rx_power ?? null,
            customer_count: node.customer_count ?? null,
            customer_name: node.customer_name || '',
            olt_id: node.olt_id ?? null,
            olt_name: node.olt_name || '',
            pon_port: node.pon_port || '',
            onu_id: node.onu_id || '',
            onu_serial: node.onu_serial || '',
            onu_mac: node.onu_mac || '',
            onu_ip: node.onu_ip || '',
            tx_power: node.tx_power ?? null,
            last_seen_at: node.last_seen_at || '',
        },
        geometry: {
            type: 'Point',
            coordinates: [Number(node.lng), Number(node.lat)],
        },
    };
};

const lineFeature = (edge) => ({
    type: 'Feature',
    properties: {
        fromKey: edge.fromKey,
        toKey: edge.toKey,
        type: edge.type || 'connection',
        label: edge.label || edge.type || 'Connection',
        status: edge.status || 'unknown',
        capacity_mbps: edge.capacity_mbps ?? null,
        traffic_mbps: edge.traffic_mbps ?? null,
        latency_ms: edge.latency_ms ?? null,
        packet_loss: edge.packet_loss ?? null,
        fiber_core: edge.fiber_core || '',
        fiber_type: edge.fiber_type || '',
        source_port: edge.source_port || '',
        target_port: edge.target_port || '',
        rx_power: edge.rx_power ?? null,
        tx_power: edge.tx_power ?? null,
        onu_id: edge.onu_id || '',
        onu_mac: edge.onu_mac || '',
    },
    geometry: {
        type: 'LineString',
        coordinates: [
            [Number(edge.from?.[1]), Number(edge.from?.[0])],
            [Number(edge.to?.[1]), Number(edge.to?.[0])],
        ],
    },
});

async function fetchVectorStyle(url, attribution) {
    const response = await fetch(url, { credentials: 'omit', cache: 'default' });
    if (!response.ok) {
        throw new Error(`Basemap returned HTTP ${response.status}`);
    }

    const style = await response.json();
    Object.values(style.sources || {}).forEach((source) => {
        const existing = source.attribution ? `${source.attribution} · ` : '';
        source.attribution = `${existing}${attribution}`;
    });
    return style;
}

const satelliteStyle = () => ({
    version: 8,
    sources: {
        'xlink-esri-imagery': {
            type: 'raster',
            tiles: [ESRI_IMAGERY_URL],
            tileSize: 256,
            minzoom: 0,
            maxzoom: 19,
            attribution:
                'Imagery tiles © Esri, Maxar, Earthstar Geographics, and the GIS User Community',
        },
        'xlink-esri-transport': {
            type: 'raster',
            tiles: [ESRI_TRANSPORT_URL],
            tileSize: 256,
            minzoom: 0,
            maxzoom: 19,
            attribution:
                'Road overlay © Esri, HERE, Garmin, and OpenStreetMap contributors',
        },
    },
    layers: [
        {
            id: 'xlink-satellite-imagery',
            type: 'raster',
            source: 'xlink-esri-imagery',
        },
        {
            id: 'xlink-satellite-road-overlay',
            type: 'raster',
            source: 'xlink-esri-transport',
            paint: { 'raster-opacity': 0.94 },
        },
    ],
});

async function resolveStyle(mode) {
    if (mode === 'satellite') return satelliteStyle();
    const attribution =
        '© OpenFreeMap · © OpenMapTiles · © OpenStreetMap contributors';
    return fetchVectorStyle(
        mode === 'dark' ? DARK_STYLE_URL : STREET_STYLE_URL,
        attribution,
    );
}

function streetViewUrl(node) {
    return `https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=${encodeURIComponent(
        `${node.lat},${node.lng}`,
    )}`;
}

function googleMapsUrl(node) {
    return `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(
        `${node.lat},${node.lng}`,
    )}`;
}

function popupContent(node) {
    const kind = KINDS[node.kind] || 'Network Device';
    const status = String(node.status || 'unknown').toLowerCase();
    const statusClass =
        status === 'online' ? 'online' : status === 'offline' ? 'offline' : 'unknown';
    const coordinates =
        `${Number(node.lat).toFixed(6)}, ${Number(node.lng).toFixed(6)}`;

    return `
        <section class="xlink-network-popup">
            <header class="xlink-network-popup__header">
                <span class="xlink-network-popup__kind">${escapeHtml(kind)}</span>
                <span class="xlink-network-popup__status ${statusClass}">${escapeHtml(status)}</span>
            </header>
            <h4 class="xlink-network-popup__title">${escapeHtml(node.label || node.id || kind)}</h4>
            ${node.customer_uid
                ? `<div class="xlink-network-popup__row"><span>Customer ID</span><strong>${escapeHtml(node.customer_uid)}</strong></div>`
                : ''}
            ${node.customer_name ? `<div class="xlink-network-popup__row"><span>Customer</span><strong>${escapeHtml(node.customer_name)}</strong></div>` : ''}
            ${node.kind === 'onu' ? `<div class="xlink-network-popup__row"><span>OLT / PON</span><strong>${escapeHtml(node.olt_name || '—')} · ${escapeHtml(node.pon_port || '—')}</strong></div>` : ''}
            ${node.kind === 'onu' && node.onu_id ? `<div class="xlink-network-popup__row"><span>ONU ID</span><strong>${escapeHtml(node.onu_id)}</strong></div>` : ''}
            ${node.onu_serial ? `<div class="xlink-network-popup__row"><span>ONU Serial</span><strong>${escapeHtml(node.onu_serial)}</strong></div>` : ''}
            ${node.onu_mac ? `<div class="xlink-network-popup__row"><span>ONU MAC</span><strong>${escapeHtml(node.onu_mac)}</strong></div>` : ''}
            ${node.onu_ip ? `<div class="xlink-network-popup__row"><span>ONU IP</span><strong>${escapeHtml(node.onu_ip)}</strong></div>` : ''}
            ${node.tx_power !== null && node.tx_power !== undefined ? `<div class="xlink-network-popup__row"><span>TX power</span><strong>${escapeHtml(Number(node.tx_power).toFixed(2))} dBm</strong></div>` : ''}
            ${node.last_seen_at ? `<div class="xlink-network-popup__row"><span>ONU last seen</span><strong>${escapeHtml(node.last_seen_at)}</strong></div>` : ''}
            ${node.kind === 'customer' && node.account_status
                ? `<div class="xlink-network-popup__row"><span>Billing account</span><strong>${escapeHtml(node.account_status)}</strong></div>`
                : ''}
            ${node.ip
                ? `<div class="xlink-network-popup__row"><span>IP address</span><strong>${escapeHtml(node.ip)}</strong></div>`
                : ''}
            ${node.router
                ? `<div class="xlink-network-popup__row"><span>Router</span><strong>${escapeHtml(node.router)}</strong></div>`
                : ''}
            ${node.vendor || node.model
                ? `<div class="xlink-network-popup__row"><span>Vendor / model</span><strong>${escapeHtml([node.vendor, node.model].filter(Boolean).join(' · '))}</strong></div>`
                : ''}
            ${node.firmware
                ? `<div class="xlink-network-popup__row"><span>Firmware</span><strong>${escapeHtml(node.firmware)}</strong></div>`
                : ''}
            ${node.serial_no
                ? `<div class="xlink-network-popup__row"><span>Serial</span><strong>${escapeHtml(node.serial_no)}</strong></div>`
                : ''}
            ${node.location
                ? `<div class="xlink-network-popup__row"><span>Location</span><strong>${escapeHtml(node.location)}</strong></div>`
                : ''}
            ${node.health_status && String(node.health_status).toLowerCase() !== status
                ? `<div class="xlink-network-popup__row"><span>Source status</span><strong>${escapeHtml(node.health_status)}</strong></div>`
                : ''}
            ${node.last_latency_ms !== null && node.last_latency_ms !== undefined && Number.isFinite(Number(node.last_latency_ms))
                ? `<div class="xlink-network-popup__row"><span>Last latency</span><strong>${escapeHtml(Number(node.last_latency_ms).toFixed(1))} ms</strong></div>`
                : ''}
            ${node.last_checked_at
                ? `<div class="xlink-network-popup__row"><span>Last checked</span><strong>${escapeHtml(node.last_checked_at)}</strong></div>`
                : ''}
            ${node.onu_total !== null && node.onu_total !== undefined
                ? `<div class="xlink-network-popup__row"><span>ONU status</span><strong>${escapeHtml(node.onu_online ?? '—')} / ${escapeHtml(node.onu_total)} online</strong></div>`
                : ''}
            ${node.customer_count !== null && node.customer_count !== undefined
                ? `<div class="xlink-network-popup__row"><span>Customers served</span><strong>${escapeHtml(node.customer_count)}</strong></div>`
                : ''}
            ${node.rx_power !== null && node.rx_power !== undefined && Number.isFinite(Number(node.rx_power))
                ? `<div class="xlink-network-popup__row"><span>RX power</span><strong>${escapeHtml(Number(node.rx_power).toFixed(2))} dBm</strong></div>`
                : ''}
            <div class="xlink-network-popup__row"><span>Coordinates</span><strong>${coordinates}</strong></div>
            <div class="xlink-network-popup__actions">
                <a class="xlink-map-action xlink-map-action--primary" href="${escapeHtml(
                    streetViewUrl(node),
                )}" target="_blank" rel="noopener noreferrer" title="Open Google Street View if imagery is available">
                    <span aria-hidden="true">↗</span> Street View
                </a>
                <a class="xlink-map-action" href="${escapeHtml(
                    googleMapsUrl(node),
                )}" target="_blank" rel="noopener noreferrer">
                    <span aria-hidden="true">⌖</span> Google Maps
                </a>
                ${['customer', 'onu'].includes(node.kind) && node.customer_url
                    ? `<a class="xlink-map-action" href="${escapeHtml(node.customer_url)}">View Customer</a>`
                    : ''}
            </div>
            <p class="xlink-network-popup__note">${node.kind === 'customer' ? 'Billing account status is not a live network-session check, so connectivity is shown as unknown here.' : (node.kind === 'onu' ? 'ONU position uses the linked customer Network Location; optical values are the latest stored OLT readings.' : 'Street imagery depends on Google Maps coverage at this location.')}</p>
        </section>
    `;
}

function fitCoordinates(map, coordinates, defaultCenter = DEFAULT_CENTER, defaultZoom = 10) {
    if (!coordinates.length) {
        map.easeTo({ center: defaultCenter, zoom: defaultZoom, pitch: 0, bearing: 0, duration: 450 });
        return;
    }

    if (coordinates.length === 1) {
        map.easeTo({ center: coordinates[0], zoom: 16, duration: 450 });
        return;
    }

    const bounds = coordinates.reduce(
        (current, point) => current.extend(point),
        new LngLatBounds(coordinates[0], coordinates[0]),
    );
    map.fitBounds(bounds, {
        padding: { top: 64, right: 72, bottom: 64, left: 72 },
        maxZoom: 16,
        duration: 450,
    });
}

function setButtonState(mode) {
    ['street', 'satellite', 'dark'].forEach((name) => {
        const button = document.getElementById(`map-base-${name}`);
        if (!button) return;
        const active = name === mode;
        button.classList.toggle('active', active);
        button.setAttribute('aria-pressed', String(active));
    });
}

function bootNetworkMap() {
    const element = document.getElementById('network-map');
    if (!element || element._maplibreMap) return;

    const data = window.XLINK_NETWORK_MAP_DATA || {};
    const allNodes = Array.isArray(data.nodes)
        ? data.nodes.filter((node) => Number.isFinite(Number(node.lat)) &&
            Number.isFinite(Number(node.lng)) &&
            Math.abs(Number(node.lat)) <= 90 &&
            Math.abs(Number(node.lng)) <= 180)
        : [];
    const allEdges = Array.isArray(data.edges) ? data.edges : [];

    const configuredBasemap = data.config?.basemap;
    let activeMode = ['street', 'satellite', 'dark'].includes(configuredBasemap)
        ? configuredBasemap
        : (document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'street');
    const configuredCenter = Array.isArray(data.config?.center) && data.config.center.length === 2
        && Number.isFinite(Number(data.config.center[0])) && Number.isFinite(Number(data.config.center[1]))
        ? [Number(data.config.center[0]), Number(data.config.center[1])]
        : DEFAULT_CENTER;
    const configuredZoom = Number.isFinite(Number(data.config?.zoom))
        ? Math.min(20, Math.max(1, Number(data.config.zoom)))
        : 10;
    let map = null;
    let styleVersion = 0;
    let initialized = false;
    let pitchEnabled = false;
    let filteredNodes = [...allNodes];
    let renderedNodes = [...allNodes];
    let filteredEdges = [...allEdges];
    const mapLayers = { connections: true, customers: true, labels: true };
    let featuresByKey = new Map();
    const popup = new Popup({
        closeButton: true,
        closeOnClick: true,
        maxWidth: '340px',
        offset: 14,
    });

    const toKey = (node) =>
        node.map_key || `${node.kind === 'router' ? 'router:' : 'device:'}${node.id}`;

    const currentFilters = () => ({
        kind: document.getElementById('map-type-filter')?.value || '',
        router: document.getElementById('map-router-filter')?.value || '',
        status: document.getElementById('map-status-filter')?.value || '',
        query: (document.getElementById('map-customer-search')?.value || '')
            .trim()
            .toLocaleLowerCase(),
    });

    const matchNode = (node, filters) => {
        if (filters.kind && node.kind !== filters.kind) return false;
        if (filters.router && node.router !== filters.router) return false;
        if (filters.status && node.status !== filters.status) return false;
        if (!filters.query) return true;

        return [
            node.id,
            node.label,
            node.customer_uid,
            node.ip,
            node.location,
            node.kind,
            node.router,
        ].some((value) =>
            String(value ?? '').toLocaleLowerCase().includes(filters.query),
        );
    };

    const renderNodeDirectory = () => {
        const container = document.getElementById('map-node-directory');
        const summary = document.getElementById('map-directory-summary');
        const countBadge = document.getElementById('map-directory-count');
        if (!container) return;

        const kindRank = { olt: 0, router: 1, onu: 2, switch: 3, 'wifi-router': 4, 'access-point': 5, customer: 6 };
        const statusRank = { offline: 0, unknown: 1, online: 2 };
        const priorityNodes = [...filteredNodes].sort((a, b) =>
            (statusRank[String(a.status || 'unknown')] ?? 1) - (statusRank[String(b.status || 'unknown')] ?? 1) ||
            (kindRank[a.kind] ?? 9) - (kindRank[b.kind] ?? 9) ||
            String(a.label || a.id || '').localeCompare(String(b.label || b.id || '')),
        );
        const displayed = priorityNodes.slice(0, 10);
        const icons = { router: 'bi-router', olt: 'bi-hdd-network', onu: 'bi-diagram-2', 'wifi-router': 'bi-wifi', 'access-point': 'bi-broadcast', switch: 'bi-ethernet', customer: 'bi-person-vcard', splitter: 'bi-diagram-3', olt_pon: 'bi-diagram-3', odf: 'bi-hdd', rack: 'bi-server', pop: 'bi-building', fiber_segment: 'bi-bezier2', cabinet: 'bi-box', pole: 'bi-signpost', splice: 'bi-link-45deg', server: 'bi-server' };
        const tones = { router: '#2563eb', olt: '#7c3aed', onu: '#0891b2', 'wifi-router': '#16a34a', 'access-point': '#ea580c', switch: '#64748b', customer: '#64748b', splitter: '#d97706', olt_pon: '#7c3aed', odf: '#0f766e', rack: '#475569', pop: '#0e7490', fiber_segment: '#a16207', cabinet: '#b45309', pole: '#ca8a04', splice: '#c2410c', server: '#4f46e5' };

        container.innerHTML = displayed.length ? displayed.map((node) => {
            const key = toKey(node);
            const kind = KINDS[node.kind] || 'Network device';
            const status = ['online', 'offline', 'unknown'].includes(String(node.status || '').toLowerCase()) ? String(node.status).toLowerCase() : 'unknown';
            const meta = node.ip || node.customer_uid || `${Number(node.lat).toFixed(4)}, ${Number(node.lng).toFixed(4)}`;
            return `<button type="button" class="network-map-directory-item" data-map-node-key="${escapeHtml(key)}" style="--dir-color:${tones[node.kind] || '#64748b'};--dir-soft:color-mix(in srgb, ${tones[node.kind] || '#64748b'} 12%, transparent)">
                <span class="network-map-directory-item__icon"><i class="bi ${icons[node.kind] || 'bi-hdd-network'}"></i></span>
                <span class="network-map-directory-item__text"><span class="network-map-directory-item__name">${escapeHtml(node.label || node.id || kind)}</span><span class="network-map-directory-item__meta">${escapeHtml(kind)} · ${escapeHtml(meta)}</span></span>
                <span class="network-map-directory-item__status ${status}">${escapeHtml(status)}</span>
            </button>`;
        }).join('') : '<div class="network-map-directory-empty"><i class="bi bi-search me-1"></i>No nodes match these filters.</div>';

        if (countBadge) countBadge.textContent = String(displayed.length);
        if (summary) summary.textContent = filteredNodes.length > 10
            ? `Showing 10 of ${filteredNodes.length} matches · offline/unknown first`
            : `${displayed.length} matching node${displayed.length === 1 ? '' : 's'} · offline/unknown first`;
    };

    const updateCount = () => {
        const result = document.getElementById('map-result-count');
        if (result) {
            result.textContent = `${renderedNodes.length} node${renderedNodes.length === 1 ? '' : 's'} on map`;
        }
        const countByStatus = (status) => renderedNodes.filter((node) => String(node.status || 'unknown').toLowerCase() === status).length;
        const online = document.getElementById('map-visible-online');
        const offline = document.getElementById('map-visible-offline');
        const unknown = document.getElementById('map-visible-unknown');
        const visibleNodes = document.getElementById('map-visible-nodes');
        const visibleLinks = document.getElementById('map-visible-links');
        if (online) online.textContent = countByStatus('online').toLocaleString();
        if (offline) offline.textContent = countByStatus('offline').toLocaleString();
        if (unknown) unknown.textContent = countByStatus('unknown').toLocaleString();
        if (visibleNodes) visibleNodes.textContent = renderedNodes.length.toLocaleString();
        if (visibleLinks) visibleLinks.textContent = (mapLayers.connections ? filteredEdges.length : 0).toLocaleString();
        renderNodeDirectory();
    };

    const updateSources = (refit = false) => {
        if (!map || !map.isStyleLoaded()) return;

        filteredNodes = allNodes.filter((node) => matchNode(node, currentFilters()));
        renderedNodes = filteredNodes.filter((node) => mapLayers.customers || node.kind !== 'customer');
        const visibleKeys = new Set(renderedNodes.map(toKey));
        filteredEdges = allEdges.filter(
            (edge) => edge.fromKey && edge.toKey &&
                visibleKeys.has(edge.fromKey) && visibleKeys.has(edge.toKey),
        );

        // Keep all filtered nodes available to the directory, while the map source
        // respects the visual layer toggles selected by the operator.
        featuresByKey = new Map(filteredNodes.map((node) => [toKey(node), node]));

        const nodeSource = map.getSource('xlink-network-nodes');
        if (nodeSource) {
            nodeSource.setData(asFeatureCollection(renderedNodes.map(pointFeature)));
        }

        const edgeSource = map.getSource('xlink-network-edges');
        if (edgeSource) {
            edgeSource.setData(asFeatureCollection((mapLayers.connections ? filteredEdges : []).map(lineFeature)));
        }

        updateCount();

        if (refit) {
            fitCoordinates(
                map,
                filteredNodes.map((node) => [Number(node.lng), Number(node.lat)]),
                configuredCenter,
                configuredZoom,
            );
        }
    };

    const attachLayers = () => {
        if (!map || !map.isStyleLoaded()) return;

        if (!map.getSource('xlink-network-edges')) {
            map.addSource('xlink-network-edges', {
                type: 'geojson',
                data: asFeatureCollection([]),
                lineMetrics: true,
            });
        }

        if (!map.getSource('xlink-network-nodes')) {
            map.addSource('xlink-network-nodes', {
                type: 'geojson',
                data: asFeatureCollection([]),
                cluster: true,
                clusterRadius: 42,
                clusterMaxZoom: 14,
            });
        }

        if (!map.getLayer('xlink-network-edges-physical')) {
            map.addLayer({
                id: 'xlink-network-edges-physical',
                type: 'line',
                source: 'xlink-network-edges',
                filter: ['!=', ['get', 'type'], 'logical_service'],
                paint: {
                    'line-color': [
                        'match', ['get', 'type'],
                        'fiber_core', '#16a34a',
                        'splitter', '#0d9488',
                        'uplink', '#2563eb',
                        '#64748b',
                    ],
                    'line-width': ['interpolate', ['linear'], ['zoom'], 5, 1.5, 15, 4],
                    'line-opacity': 0.86,
                },
            });
        }

        if (!map.getLayer('xlink-network-edges-logical')) {
            map.addLayer({
                id: 'xlink-network-edges-logical',
                type: 'line',
                source: 'xlink-network-edges',
                filter: ['==', ['get', 'type'], 'logical_service'],
                paint: {
                    'line-color': '#f59e0b',
                    'line-width': ['interpolate', ['linear'], ['zoom'], 5, 1.5, 15, 3],
                    'line-dasharray': [2, 1.5],
                    'line-opacity': 0.95,
                },
            });
        }

        if (!map.getLayer('xlink-network-clusters')) {
            map.addLayer({
                id: 'xlink-network-clusters',
                type: 'circle',
                source: 'xlink-network-nodes',
                filter: ['has', 'point_count'],
                paint: {
                    'circle-color': [
                        'step', ['get', 'point_count'],
                        '#2563eb', 10, '#7c3aed', 50, '#dc2626',
                    ],
                    'circle-radius': [
                        'step', ['get', 'point_count'],
                        18, 10, 23, 50, 29,
                    ],
                    'circle-stroke-color': '#ffffff',
                    'circle-stroke-width': 2.5,
                    'circle-opacity': 0.95,
                },
            });
        }

        if (!map.getLayer('xlink-network-cluster-count')) {
            map.addLayer({
                id: 'xlink-network-cluster-count',
                type: 'symbol',
                source: 'xlink-network-nodes',
                filter: ['has', 'point_count'],
                layout: {
                    'text-field': ['get', 'point_count_abbreviated'],
                    'text-size': 12,
                    'text-font': ['Noto Sans Bold'],
                },
                paint: { 'text-color': '#ffffff' },
            });
        }

        if (!map.getLayer('xlink-network-nodes')) {
            map.addLayer({
                id: 'xlink-network-nodes',
                type: 'circle',
                source: 'xlink-network-nodes',
                filter: ['!', ['has', 'point_count']],
                paint: {
                    'circle-color': [
                        'match', ['get', 'kind'],
                        'router', COLORS.router,
                        'olt', COLORS.olt,
                        'onu', COLORS.onu,
                        'wifi-router', COLORS['wifi-router'],
                        'access-point', COLORS['access-point'],
                        'switch', COLORS.switch,
                        'customer', COLORS.customer,
                        'splitter', COLORS.splitter,
                        'olt_pon', COLORS.olt_pon,
                        'odf', COLORS.odf,
                        'rack', COLORS.rack,
                        'pop', COLORS.pop,
                        'fiber_segment', COLORS.fiber_segment,
                        'cabinet', COLORS.cabinet,
                        'pole', COLORS.pole,
                        'splice', COLORS.splice,
                        'server', COLORS.server,
                        '#64748b',
                    ],
                    'circle-radius': [
                        'match', ['get', 'kind'],
                        'router', 10,
                        'olt', 11,
                        'onu', 8.5,
                        'customer', 6,
                        8,
                    ],
                    'circle-stroke-color': [
                        'match', ['get', 'status'],
                        'online', '#22c55e',
                        'offline', '#ef4444',
                        '#94a3b8',
                    ],
                    'circle-stroke-width': 3,
                    'circle-opacity': 0.98,
                },
            });
        }

        if (!map.getLayer('xlink-network-labels')) {
            map.addLayer({
                id: 'xlink-network-labels',
                type: 'symbol',
                source: 'xlink-network-nodes',
                minzoom: 13,
                filter: ['!', ['has', 'point_count']],
                layout: {
                    'text-field': ['get', 'short_label'],
                    'text-font': ['Noto Sans Regular'],
                    'text-size': ['interpolate', ['linear'], ['zoom'], 13, 10, 17, 13],
                    'text-offset': [0, 1.15],
                    'text-anchor': 'top',
                    'text-max-width': 12,
                    'text-optional': true,
                    'symbol-sort-key': [
                        'match', ['get', 'kind'],
                        'olt', 0,
                        'router', 1,
                        'onu', 2,
                        3,
                    ],
                },
                paint: {
                    'text-color': '#ffffff',
                    'text-halo-color': '#172033',
                    'text-halo-width': 1.5,
                    'text-halo-blur': 0.3,
                },
            });
        }

        if (map.getLayer('xlink-network-labels')) {
            map.setLayoutProperty('xlink-network-labels', 'visibility', mapLayers.labels ? 'visible' : 'none');
        }

        updateSources(false);
    };

    const setBasemap = async (mode) => {
        if (!map || !['street', 'satellite', 'dark'].includes(mode)) return;
        activeMode = mode;
        setButtonState(mode);
        document.querySelectorAll('[data-map-tools-basemap]').forEach((button) => {
            const active = button.dataset.mapToolsBasemap === mode;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', String(active));
        });
        const requestId = ++styleVersion;
        const loading = document.getElementById('map-loading-state');
        if (loading) {
            loading.classList.remove('map-loading-state--hidden', 'map-loading-state--empty');
            loading.querySelector('strong')?.replaceChildren(document.createTextNode('Switching map layer…'));
        }

        try {
            const style = await resolveStyle(mode);
            if (requestId !== styleVersion || !map) return;
            map.once('style.load', () => {
                if (requestId !== styleVersion || !map) return;
                attachLayers();
                if (loading) loading.classList.add('map-loading-state--hidden');
            });
            map.setStyle(style);
        } catch (error) {
            console.error('[X-Link Network Map] Basemap switch failed:', error);
            if (loading) {
                loading.classList.remove('map-loading-state--hidden');
                loading.classList.add('map-loading-state--error');
                loading.innerHTML =
                    '<strong>Map layer unavailable</strong><span>Check your internet connection and retry the map style.</span>';
            }
        }
    };

    const onMapClick = (event) => {
        if (!map || !map.isStyleLoaded()) return;

        const layerIds = [
            'xlink-network-clusters',
            'xlink-network-cluster-count',
            'xlink-network-nodes',
            'xlink-network-labels',
            'xlink-network-edges-physical',
            'xlink-network-edges-logical',
        ].filter((id) => map.getLayer(id));

        const hits = map.queryRenderedFeatures(event.point, { layers: layerIds });
        if (!hits.length) {
            popup.remove();
            return;
        }

        const hit = hits[0];
        const props = hit.properties || {};

        if (props.cluster_id !== undefined || props.point_count !== undefined) {
            const source = map.getSource('xlink-network-nodes');
            if (source?.getClusterExpansionZoom) {
                source.getClusterExpansionZoom(props.cluster_id, (error, zoom) => {
                    if (error) return;
                    map.easeTo({
                        center: hit.geometry.coordinates,
                        zoom,
                        duration: 450,
                    });
                });
            }
            return;
        }

        if (props.key && featuresByKey.has(props.key)) {
            const node = featuresByKey.get(props.key);
            popup
                .setLngLat([Number(node.lng), Number(node.lat)])
                .setHTML(popupContent(node))
                .addTo(map);
            return;
        }

        if (props.type || props.label) {
            const toNumber = (value) => value !== null && value !== undefined && value !== '' && Number.isFinite(Number(value)) ? Number(value) : null;
            const capacity = toNumber(props.capacity_mbps);
            const traffic = toNumber(props.traffic_mbps);
            const latency = toNumber(props.latency_ms);
            const packetLoss = toNumber(props.packet_loss);
            const utilization = capacity > 0 && traffic !== null ? `${((traffic / capacity) * 100).toFixed(1)}%` : 'Not reported';
            const row = (label, value) => `<div class="xlink-network-popup__row"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong></div>`;
            const numberRow = (label, value, suffix, digits = 2) => row(label, value === null ? 'Not reported' : `${value.toFixed(digits)} ${suffix}`);
            const status = String(props.status || 'unknown').toLowerCase();
            const safeStatus = ['online', 'offline', 'unknown', 'up', 'down', 'active', 'inactive'].includes(status) ? status : 'unknown';
            const html = `
                <section class="xlink-network-popup xlink-network-popup--connection">
                    <header class="xlink-network-popup__header"><span class="xlink-network-popup__kind">Network connection</span><span class="xlink-network-popup__status ${safeStatus === 'online' || safeStatus === 'up' || safeStatus === 'active' ? 'online' : (safeStatus === 'offline' || safeStatus === 'down' || safeStatus === 'inactive' ? 'offline' : 'unknown')}">${escapeHtml(safeStatus)}</span></header>
                    <h4 class="xlink-network-popup__title">${escapeHtml(props.label || 'Connection')}</h4>
                    ${row('Connection type', String(props.type || 'connection').replace(/_/g, ' '))}
                    ${row('Source endpoint', `${props.fromKey || '—'}${props.source_port ? ` · Port ${props.source_port}` : ''}`)}
                    ${row('Target endpoint', `${props.toKey || '—'}${props.target_port ? ` · Port ${props.target_port}` : ''}`)}
                    ${numberRow('Capacity', capacity, 'Mbps', 1)}
                    ${numberRow('Traffic', traffic, 'Mbps', 2)}
                    ${row('Utilization', utilization)}
                    ${numberRow('Latency', latency, 'ms', 1)}
                    ${numberRow('Packet loss', packetLoss, '%', 2)}
                    ${props.rx_power !== undefined && props.rx_power !== null ? row('ONU RX power', `${props.rx_power} dBm`) : ''}
                    ${props.tx_power !== undefined && props.tx_power !== null ? row('ONU TX power', `${props.tx_power} dBm`) : ''}
                    ${props.onu_id ? row('ONU ID', props.onu_id) : ''}
                    ${props.onu_mac ? row('ONU MAC', props.onu_mac) : ''}
                    ${props.fiber_type ? row('Fiber type', props.fiber_type) : ''}
                    ${props.fiber_core ? row('Fiber core', props.fiber_core) : ''}
                </section>`;
            popup.setLngLat(event.lngLat).setHTML(html).addTo(map);
        }
    };

    const mapClickHandler = () => {
        document.getElementById('map-node-directory')?.addEventListener('click', (event) => {
            const item = event.target.closest('[data-map-node-key]');
            if (!item || !map) return;
            const node = featuresByKey.get(item.dataset.mapNodeKey);
            if (!node) return;
            const point = [Number(node.lng), Number(node.lat)];
            map.easeTo({ center: point, zoom: Math.max(map.getZoom(), 15), duration: 550 });
            popup.setLngLat(point).setHTML(popupContent(node)).addTo(map);
        });

        const options = [
            document.getElementById('map-type-filter'),
            document.getElementById('map-router-filter'),
            document.getElementById('map-status-filter'),
        ];
        options.forEach((select) => select?.addEventListener('change', () => updateSources(true)));
        document.getElementById('map-customer-search')?.addEventListener('input', () => updateSources(true));
        document.getElementById('map-fit')?.addEventListener('click', () => {
            fitCoordinates(map, filteredNodes.map((node) => [Number(node.lng), Number(node.lat)]), configuredCenter, configuredZoom);
        });
        document.getElementById('map-reset')?.addEventListener('click', () => {
            options.forEach((select) => { if (select) select.value = ''; });
            const search = document.getElementById('map-customer-search');
            if (search) search.value = '';
            updateSources(true);
        });

        const bindLayerToggle = (id, key) => {
            const button = document.getElementById(id);
            if (!button) return;
            button.addEventListener('click', () => {
                mapLayers[key] = !mapLayers[key];
                button.classList.toggle('active', mapLayers[key]);
                button.setAttribute('aria-pressed', String(mapLayers[key]));
                const toolsKey = key === 'connections' ? 'links' : key;
                document.querySelectorAll(`[data-map-tools-layer="${toolsKey}"]`).forEach((toolsButton) => {
                    toolsButton.setAttribute('aria-pressed', String(mapLayers[key]));
                    toolsButton.classList.toggle('is-active', mapLayers[key]);
                });
                if (key === 'labels' && map?.getLayer('xlink-network-labels')) {
                    map.setLayoutProperty('xlink-network-labels', 'visibility', mapLayers.labels ? 'visible' : 'none');
                }
                updateSources(false);
            });
        };
        bindLayerToggle('map-toggle-links', 'connections');
        bindLayerToggle('map-toggle-customers', 'customers');
        bindLayerToggle('map-toggle-labels', 'labels');

        ['street', 'satellite', 'dark'].forEach((mode) => {
            document.getElementById(`map-base-${mode}`)?.addEventListener('click', () => {
                void setBasemap(mode);
            });
        });

        const pitchButton = document.getElementById('map-pitch-toggle');
        pitchButton?.addEventListener('click', () => {
            pitchEnabled = !pitchEnabled;
            pitchButton.classList.toggle('active', pitchEnabled);
            pitchButton.setAttribute('aria-pressed', String(pitchEnabled));
            pitchButton.innerHTML = pitchEnabled
                ? '<i class="bi bi-box me-1"></i>Flat view'
                : '<i class="bi bi-boxes me-1"></i>3D view';
            map.easeTo({
                pitch: pitchEnabled ? 55 : 0,
                bearing: pitchEnabled ? -18 : 0,
                duration: 550,
            });
        });

        map.on('click', onMapClick);
        map.on('mousemove', (event) => {
            const layerIds = [
                'xlink-network-clusters',
                'xlink-network-cluster-count',
                'xlink-network-nodes',
                'xlink-network-labels',
                'xlink-network-edges-physical',
                'xlink-network-edges-logical',
            ].filter((id) => map.getLayer(id));
            const hits = map.queryRenderedFeatures(event.point, { layers: layerIds });
            map.getCanvas().style.cursor = hits.length ? 'pointer' : '';
        });
    };

    const createMapToolsControl = () => {
        let controlElement = null;

        return {
            onAdd: (activeMap) => {
                const urls = data.tools || {};
                const container = document.createElement('div');
                container.className = 'maplibregl-ctrl xlink-map-tools';
                container.innerHTML = `
                    <button class="xlink-map-tools__toggle" type="button" title="Show map tools"
                        aria-label="Show map tools" aria-haspopup="true" aria-expanded="false"
                        aria-controls="xlink-map-tools-panel">
                        <i class="bi bi-grid-3x3-gap-fill" aria-hidden="true"></i>
                    </button>
                    <section id="xlink-map-tools-panel" class="xlink-map-tools__panel" hidden aria-label="Map tools">
                        <header class="xlink-map-tools__header">
                            <strong><i class="bi bi-compass me-1" aria-hidden="true"></i>Map tools</strong>
                            <button type="button" class="xlink-map-tools__close" data-map-tools-action="close" aria-label="Close map tools">×</button>
                        </header>
                        <div class="xlink-map-tools__section">
                            <span class="xlink-map-tools__label">Navigate</span>
                            <div class="xlink-map-tools__grid">
                                <button type="button" data-map-tools-action="zoom-in"><i class="bi bi-plus-lg"></i>Zoom in</button>
                                <button type="button" data-map-tools-action="zoom-out"><i class="bi bi-dash-lg"></i>Zoom out</button>
                                <button type="button" data-map-tools-action="fit"><i class="bi bi-bounding-box"></i>Fit visible</button>
                                <button type="button" data-map-tools-action="north"><i class="bi bi-compass"></i>Reset north</button>
                                <button type="button" data-map-tools-action="locate"><i class="bi bi-crosshair"></i>My location</button>
                                <button type="button" data-map-tools-action="fullscreen"><i class="bi bi-arrows-fullscreen"></i>Fullscreen</button>
                                <button type="button" data-map-tools-action="3d"><i class="bi bi-boxes"></i>3D view</button>
                                <button type="button" data-map-tools-action="find"><i class="bi bi-search"></i>Find node</button>
                                <button type="button" data-map-tools-action="racks"><i class="bi bi-server"></i>Show racks</button>
                            </div>
                        </div>
                        <div class="xlink-map-tools__section">
                            <span class="xlink-map-tools__label">Map style</span>
                            <div class="xlink-map-tools__styles">
                                <button type="button" data-map-tools-basemap="street" class="is-active">Street</button>
                                <button type="button" data-map-tools-basemap="satellite">Satellite</button>
                                <button type="button" data-map-tools-basemap="dark">Dark</button>
                            </div>
                        </div>
                        <div class="xlink-map-tools__section">
                            <span class="xlink-map-tools__label">Visible layers</span>
                            <div class="xlink-map-tools__layers">
                                <button type="button" data-map-tools-layer="links" aria-pressed="true"><i class="bi bi-bezier2"></i>Connections</button>
                                <button type="button" data-map-tools-layer="customers" aria-pressed="true"><i class="bi bi-people"></i>Customers</button>
                                <button type="button" data-map-tools-layer="labels" aria-pressed="true"><i class="bi bi-tag"></i>Labels</button>
                            </div>
                        </div>
                        <div class="xlink-map-tools__section">
                            <span class="xlink-map-tools__label">Network operations</span>
                            <nav class="xlink-map-tools__links" aria-label="Network operations">
                                <a href="${escapeHtml(urls.designer || '#')}"><i class="bi bi-pencil-square"></i><span><strong>Edit map / Add node</strong><small>Create and verify splitter, OLT/PON, ODF, rack, POP, fiber, cabinet, pole, splice or server assets</small></span></a>
                                <a href="${escapeHtml(urls.designer || '#')}"><i class="bi bi-bezier2"></i><span><strong>Draw / Advanced Link</strong><small>Manage endpoints, ports, fiber cores and draft connections</small></span></a>
                                <a href="${escapeHtml(urls.live || '#')}"><i class="bi bi-diagram-3"></i><span><strong>Live topology</strong><small>Trace published OLT → splitter → ONU paths</small></span></a>
                                <a href="${escapeHtml(urls.fiber || '#')}"><i class="bi bi-bezier"></i><span><strong>Manual fiber mapping</strong><small>Cable, splice, splitter ports, GPS and ONU mapping register</small></span></a>
                                <a href="${escapeHtml(urls.history || '#')}"><i class="bi bi-clock-history"></i><span><strong>Device health history</strong><small>Review saved network device checks</small></span></a>
                            </nav>
                            <p class="xlink-map-tools__note">Router, OLT, ONU, switch, AP and customer markers are inventory-linked to prevent duplicate records. Custom topology edits remain drafts until verified and published.</p>
                        </div>
                        <div class="xlink-map-tools__section">
                            <span class="xlink-map-tools__label">Data</span>
                            <button type="button" class="xlink-map-tools__export" data-map-tools-action="export"><i class="bi bi-download"></i>Export visible data (GeoJSON)</button>
                            <small class="xlink-map-tools__note">Exports filtered nodes and links. Background map tiles are not included.</small>
                        </div>
                        <p class="xlink-map-tools__status" role="status" aria-live="polite"></p>
                    </section>
                `;

                const toggle = container.querySelector('.xlink-map-tools__toggle');
                const panel = container.querySelector('.xlink-map-tools__panel');
                const status = container.querySelector('.xlink-map-tools__status');
                const setOpen = (open) => {
                    panel.hidden = !open;
                    toggle.setAttribute('aria-expanded', String(open));
                    toggle.title = open ? 'Hide map tools' : 'Show map tools';
                    toggle.setAttribute('aria-label', toggle.title);
                };
                const setStatus = (message) => {
                    if (status && container.isConnected) status.textContent = message;
                };

                container.querySelectorAll('[data-map-tools-basemap]').forEach((button) => {
                    const active = button.dataset.mapToolsBasemap === activeMode;
                    button.classList.toggle('is-active', active);
                    button.setAttribute('aria-pressed', String(active));
                });
                container.querySelectorAll('[data-map-tools-layer]').forEach((button) => {
                    const key = button.dataset.mapToolsLayer === 'links' ? 'connections' : button.dataset.mapToolsLayer;
                    button.setAttribute('aria-pressed', String(mapLayers[key]));
                    button.classList.toggle('is-active', Boolean(mapLayers[key]));
                });

                toggle.addEventListener('click', () => setOpen(panel.hidden));
                container.addEventListener('click', (event) => {
                    const layerButton = event.target.closest('[data-map-tools-layer]');
                    if (layerButton) {
                        const key = layerButton.dataset.mapToolsLayer;
                        const sourceId = key === 'links' ? 'map-toggle-links' : `map-toggle-${key}`;
                        document.getElementById(sourceId)?.click();
                        const sourceButton = document.getElementById(sourceId);
                        layerButton.setAttribute('aria-pressed', String(sourceButton?.getAttribute('aria-pressed') === 'true'));
                        layerButton.classList.toggle('is-active', sourceButton?.getAttribute('aria-pressed') === 'true');
                        return;
                    }

                    const styleButton = event.target.closest('[data-map-tools-basemap]');
                    if (styleButton) {
                        const mode = styleButton.dataset.mapToolsBasemap;
                        void setBasemap(mode);
                        container.querySelectorAll('[data-map-tools-basemap]').forEach((button) => {
                            const active = button.dataset.mapToolsBasemap === mode;
                            button.classList.toggle('is-active', active);
                            button.setAttribute('aria-pressed', String(active));
                        });
                        return;
                    }

                    const actionButton = event.target.closest('[data-map-tools-action]');
                    if (!actionButton) return;
                    switch (actionButton.dataset.mapToolsAction) {
                        case 'close':
                            setOpen(false);
                            toggle.focus();
                            break;
                        case 'zoom-in':
                            activeMap.zoomIn({ duration: 250 });
                            break;
                        case 'zoom-out':
                            activeMap.zoomOut({ duration: 250 });
                            break;
                        case 'fit':
                            document.getElementById('map-fit')?.click();
                            break;
                        case 'north':
                            activeMap.easeTo({ bearing: 0, pitch: 0, duration: 350 });
                            pitchEnabled = false;
                            const pitchButton = document.getElementById('map-pitch-toggle');
                            if (pitchButton) {
                                pitchButton.classList.remove('active');
                                pitchButton.setAttribute('aria-pressed', 'false');
                                pitchButton.innerHTML = '<i class="bi bi-boxes me-1"></i>3D view';
                            }
                            break;
                        case '3d':
                            document.getElementById('map-pitch-toggle')?.click();
                            break;
                        case 'find':
                            document.getElementById('map-customer-search')?.focus();
                            setOpen(false);
                            break;
                        case 'racks': {
                            const typeFilter = document.getElementById('map-type-filter');
                            if (typeFilter) {
                                typeFilter.value = 'rack';
                                typeFilter.dispatchEvent(new Event('change', { bubbles: true }));
                            }
                            setStatus('Showing rack records in the current map view.');
                            break;
                        }
                        case 'locate':
                            if (!navigator.geolocation) {
                                setStatus('Location is not available in this browser.');
                                break;
                            }
                            setStatus('Requesting location permission…');
                            navigator.geolocation.getCurrentPosition(
                                (position) => {
                                    if (!container.isConnected) return;
                                    activeMap.flyTo({
                                        center: [position.coords.longitude, position.coords.latitude],
                                        zoom: 16,
                                        duration: 700,
                                    });
                                    setStatus('Map centered on your current browser location.');
                                },
                                (error) => {
                                    const messages = {
                                        1: 'Location permission was denied.',
                                        2: 'Your current location is unavailable.',
                                        3: 'Location request timed out.',
                                    };
                                    setStatus(messages[error.code] || 'Could not determine your location.');
                                },
                                { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 },
                            );
                            break;
                        case 'fullscreen':
                            if (document.fullscreenElement) {
                                void document.exitFullscreen().catch(() => setStatus('Could not exit fullscreen.'));
                            } else if (activeMap.getContainer().requestFullscreen) {
                                void activeMap.getContainer().requestFullscreen().catch(() => setStatus('Fullscreen is unavailable.'));
                            } else {
                                setStatus('Fullscreen is not supported in this browser.');
                            }
                            break;
                        case 'export': {
                            const features = [
                                ...filteredNodes.map(pointFeature),
                                ...filteredEdges.map(lineFeature),
                            ];
                            const exportData = {
                                type: 'FeatureCollection',
                                name: 'X-Link Network Map',
                                exported_at: new Date().toISOString(),
                                metadata: {
                                    node_count: filteredNodes.length,
                                    link_count: filteredEdges.length,
                                    basemap: activeMode,
                                    note: 'Map data only; background map tiles are not included.',
                                },
                                features,
                            };
                            const blob = new Blob([JSON.stringify(exportData, null, 2)], {
                                type: 'application/geo+json;charset=utf-8',
                            });
                            const url = URL.createObjectURL(blob);
                            const download = document.createElement('a');
                            download.href = url;
                            download.download = `xlink-network-map-${new Date().toISOString().slice(0, 10)}.geojson`;
                            document.body.appendChild(download);
                            download.click();
                            download.remove();
                            window.setTimeout(() => URL.revokeObjectURL(url), 1000);
                            setStatus(`Exported ${filteredNodes.length} nodes and ${filteredEdges.length} links.`);
                            break;
                        }
                    }
                });

                controlElement = container;
                return container;
            },
            onRemove: () => {
                controlElement?.remove();
                controlElement = null;
            },
        };
    };

    setButtonState(activeMode);

    const loading = document.getElementById('map-loading-state');
    if (loading) {
        loading.classList.remove('map-loading-state--hidden', 'map-loading-state--empty');
        loading.querySelector('strong')?.replaceChildren(document.createTextNode('Loading vector map…'));
    }

    resolveStyle(activeMode)
        .then((style) => {
            if (!element.isConnected || element._maplibreMap) return;

            map = new MapLibreMap({
                container: element,
                style,
                center: configuredCenter,
                zoom: configuredZoom,
                minZoom: 3,
                maxZoom: 20,
                maxPitch: 65,
                attributionControl: { compact: true },
                cooperativeGestures: true,
                renderWorldCopies: false,
            });

            element._maplibreMap = map;
            map.addControl(new NavigationControl({ visualizePitch: true }), 'top-right');
            map.addControl(new FullscreenControl(), 'top-right');
            map.addControl(createMapToolsControl(), 'top-left');
            map.addControl(new ScaleControl({ unit: 'metric', maxWidth: 120 }), 'bottom-left');

            map.once('load', () => {
                if (!element.isConnected) return;
                initialized = true;
                attachLayers();
                mapClickHandler();
                updateSources(true);
                if (loading) loading.classList.add('map-loading-state--hidden');
                element.setAttribute('data-map-ready', 'true');
                setTimeout(() => map?.resize(), 80);
            });

            map.on('style.load', () => {
                if (!initialized) return;
                attachLayers();
                if (loading) loading.classList.add('map-loading-state--hidden');
            });

            map.on('error', (event) => {
                console.warn('[X-Link Network Map] Map event:', event?.error?.message || event);
            });

            const controller = new AbortController();
            element._mapEventController = controller;
            window.addEventListener('resize', () => {
                if (element.isConnected) map?.resize();
            }, { signal: controller.signal });
            document.addEventListener('navbarVerticalToggled', () => {
                setTimeout(() => { if (element.isConnected) map?.resize(); }, 250);
            }, { signal: controller.signal });
        })
        .catch((error) => {
            console.error('[X-Link Network Map] Could not initialize map:', error);
            if (loading) {
                loading.classList.remove('map-loading-state--hidden', 'map-loading-state--empty');
                loading.classList.add('map-loading-state--error');
                loading.innerHTML =
                    '<strong>Map could not load</strong><span>The basemap server may be temporarily unavailable. Please retry or refresh this page.</span>';
            }
        });
}

document.addEventListener('livewire:navigating', () => {
    const element = document.getElementById('network-map');
    if (element?._mapEventController) {
        element._mapEventController.abort();
        element._mapEventController = null;
    }
    if (element?._maplibreMap) {
        element._maplibreMap.remove();
        element._maplibreMap = null;
    }
});

const boot = () => {
    window.initNetworkMap = bootNetworkMap;
    if (document.getElementById('network-map')) bootNetworkMap();
};

document.addEventListener('livewire:navigated', boot);
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
