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
};
const KINDS = {
    router: 'MikroTik Router',
    olt: 'OLT',
    onu: 'ONU',
    'wifi-router': 'WiFi Router',
    'access-point': 'Access Point',
    switch: 'Switch',
    customer: 'Customer',
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
    const key = `${node.kind === 'router' ? 'router:' : 'device:'}${node.id}`;
    return {
        type: 'Feature',
        id: key,
        properties: {
            key,
            kind: node.kind || 'device',
            label: node.label || node.id || 'Device',
            short_label: node.label || node.id || 'Device',
            status: node.status || 'unknown',
            ip: node.ip || '',
            location: node.location || '',
            router: node.router || '',
            customer_uid: node.customer_uid || '',
            customer_url: node.customer_url || '',
            latitude: Number(node.lat),
            longitude: Number(node.lng),
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
            ${node.ip
                ? `<div class="xlink-network-popup__row"><span>IP address</span><strong>${escapeHtml(node.ip)}</strong></div>`
                : ''}
            ${node.router
                ? `<div class="xlink-network-popup__row"><span>Router</span><strong>${escapeHtml(node.router)}</strong></div>`
                : ''}
            ${node.location
                ? `<div class="xlink-network-popup__row"><span>Location</span><strong>${escapeHtml(node.location)}</strong></div>`
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
                ${node.kind === 'customer' && node.customer_url
                    ? `<a class="xlink-map-action" href="${escapeHtml(node.customer_url)}">View Customer</a>`
                    : ''}
            </div>
            <p class="xlink-network-popup__note">Street imagery depends on Google Maps coverage at this location.</p>
        </section>
    `;
}

function fitCoordinates(map, coordinates) {
    if (!coordinates.length) {
        map.easeTo({ center: DEFAULT_CENTER, zoom: 10, pitch: 0, bearing: 0, duration: 450 });
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

    let activeMode =
        document.documentElement.getAttribute('data-bs-theme') === 'dark'
            ? 'dark'
            : 'street';
    let map = null;
    let styleVersion = 0;
    let initialized = false;
    let pitchEnabled = false;
    let filteredNodes = [...allNodes];
    let filteredEdges = [...allEdges];
    let featuresByKey = new Map();
    const popup = new Popup({
        closeButton: true,
        closeOnClick: true,
        maxWidth: '340px',
        offset: 14,
    });

    const toKey = (node) =>
        `${node.kind === 'router' ? 'router:' : 'device:'}${node.id}`;

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

    const updateCount = () => {
        const result = document.getElementById('map-result-count');
        if (result) {
            result.textContent = `${filteredNodes.length} node${filteredNodes.length === 1 ? '' : 's'} shown`;
        }
    };

    const updateSources = (refit = false) => {
        if (!map || !map.isStyleLoaded()) return;

        filteredNodes = allNodes.filter((node) => matchNode(node, currentFilters()));
        const visibleKeys = new Set(filteredNodes.map(toKey));
        filteredEdges = allEdges.filter(
            (edge) => edge.fromKey && edge.toKey &&
                visibleKeys.has(edge.fromKey) && visibleKeys.has(edge.toKey),
        );

        featuresByKey = new Map(filteredNodes.map((node) => [toKey(node), node]));

        const nodeSource = map.getSource('xlink-network-nodes');
        if (nodeSource) {
            nodeSource.setData(asFeatureCollection(filteredNodes.map(pointFeature)));
        }

        const edgeSource = map.getSource('xlink-network-edges');
        if (edgeSource) {
            edgeSource.setData(asFeatureCollection(filteredEdges.map(lineFeature)));
        }

        updateCount();

        if (refit) {
            fitCoordinates(
                map,
                filteredNodes.map((node) => [Number(node.lng), Number(node.lat)]),
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

        updateSources(false);
    };

    const setBasemap = async (mode) => {
        if (!map || !['street', 'satellite', 'dark'].includes(mode)) return;
        activeMode = mode;
        setButtonState(mode);
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
            const html = `
                <div class="xlink-network-popup">
                    <span class="xlink-network-popup__kind">Network connection</span>
                    <h4 class="xlink-network-popup__title">${escapeHtml(props.label || 'Connection')}</h4>
                    <div class="xlink-network-popup__row"><span>Type</span><strong>${escapeHtml(
                        String(props.type || 'connection').replace(/_/g, ' '),
                    )}</strong></div>
                </div>`;
            popup.setLngLat(event.lngLat).setHTML(html).addTo(map);
        }
    };

    const mapClickHandler = () => {
        const options = [
            document.getElementById('map-type-filter'),
            document.getElementById('map-router-filter'),
            document.getElementById('map-status-filter'),
        ];
        options.forEach((select) => select?.addEventListener('change', () => updateSources(true)));
        document.getElementById('map-customer-search')?.addEventListener('input', () => updateSources(true));
        document.getElementById('map-fit')?.addEventListener('click', () => {
            fitCoordinates(map, filteredNodes.map((node) => [Number(node.lng), Number(node.lat)]));
        });
        document.getElementById('map-reset')?.addEventListener('click', () => {
            options.forEach((select) => { if (select) select.value = ''; });
            const search = document.getElementById('map-customer-search');
            if (search) search.value = '';
            updateSources(true);
        });

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
                center: DEFAULT_CENTER,
                zoom: 10,
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
