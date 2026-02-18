/**
 * taxitime-worker.js (Heading-Aware Edition)
 * Nutzt das Flugzeug-Heading, um die wahrscheinliche Startbahnrichtung vorzufiltern.
 */

const TAXI_SPEED_MS = 16 * 0.514444;
const RUNWAY_ENTRY_DELAY = 60;

// --- 1. Helfer ---
class MinHeap {
    constructor() { this.heap = []; }
    push(node) { this.heap.push(node); this.bubbleUp(this.heap.length - 1); }
    pop() {
        if (this.heap.length === 0) return null;
        const top = this.heap[0];
        const bottom = this.heap.pop();
        if (this.heap.length > 0) { this.heap[0] = bottom; this.bubbleDown(0); }
        return top;
    }
    bubbleUp(idx) {
        while (idx > 0) {
            const pIdx = (idx - 1) >>> 1;
            if (this.heap[pIdx].cost <= this.heap[idx].cost) break;
            [this.heap[pIdx], this.heap[idx]] = [this.heap[idx], this.heap[pIdx]];
            idx = pIdx;
        }
    }
    bubbleDown(idx) {
        while (true) {
            let swap = null, l = (idx << 1) + 1, r = l + 1;
            if (l < this.heap.length && this.heap[l].cost < this.heap[idx].cost) swap = l;
            if (r < this.heap.length && this.heap[r].cost < (swap === null ? this.heap[idx].cost : this.heap[l].cost)) swap = r;
            if (swap === null) break;
            [this.heap[idx], this.heap[swap]] = [this.heap[swap], this.heap[idx]];
            idx = swap;
        }
    }
    get size() { return this.heap.length; }
}

let graphs = {};

function haversineMeters(lat1, lon1, lat2, lon2) {
    const R = 6371000; const rad = Math.PI/180;
    const dLat=(lat2-lat1)*rad, dLon=(lon2-lon1)*rad;
    const a = Math.sin(dLat/2)**2 + Math.cos(lat1*rad)*Math.cos(lat2*rad)*Math.sin(dLon/2)**2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
}

function coordKey(lat, lon) {
    return `${Math.round(lat * 100000)},${Math.round(lon * 100000)}`;
}
const GRID_SIZE = 0.005;
function gridKey(lat, lon) {
    return `${Math.floor(lat / GRID_SIZE)},${Math.floor(lon / GRID_SIZE)}`;
}

// Winkel-Differenz (-180 bis +180)
function angleDiff(a, b) {
    let d = a - b;
    while (d <= -180) d += 360;
    while (d > 180) d -= 360;
    return d;
}

// Designator (z.B. "25C") in Heading (250) umrechnen
function getRunwayHeading(des) {
    const m = des.match(/^(\d{2})/);
    if(m) return parseInt(m[1], 10) * 10;
    return null;
}

self.onmessage = function(e) {
    const { action, payload } = e.data;

    if (action === 'INIT_GRAPH') {
        const { icao, taxiways, runways } = payload;

        const g = {
            nodes: new Map(),
            runwayNodes: new Map(),
            spatial: new Map()
        };

        const addNode = (lat, lon, isTaxi = false) => {
            const k = coordKey(lat, lon);
            if (!g.nodes.has(k)) {
                const node = { id: k, lat, lon, edges: [], isTaxi };
                g.nodes.set(k, node);
                const gk = gridKey(lat, lon);
                if(!g.spatial.has(gk)) g.spatial.set(gk, []);
                g.spatial.get(gk).push(node);
            }
            return g.nodes.get(k);
        };

        // 1. Taxiways
        if(taxiways){
            for (let i = 0; i < taxiways.length; i += 4) {
                const lat1=taxiways[i], lon1=taxiways[i+1], lat2=taxiways[i+2], lon2=taxiways[i+3];
                const d = haversineMeters(lat1, lon1, lat2, lon2);
                if(d < 0.1) continue;
                const n1 = addNode(lat1, lon1, true);
                const n2 = addNode(lat2, lon2, true);
                n1.edges.push({ target: n2.id, dist: d });
                n2.edges.push({ target: n1.id, dist: d });
            }
        }

        // 2. Runways (Aggressive Bridging)
        if (Array.isArray(runways)) {
            for (const ep of runways) {
                const rwyNode = addNode(ep.lat, ep.lon, false);

                // Wir speichern das Set an Nodes pro Designator
                if (!g.runwayNodes.has(ep.designator)) g.runwayNodes.set(ep.designator, new Set());
                g.runwayNodes.get(ep.designator).add(rwyNode.id);

                let bestNodeId = null;
                let minD = 1000;

                const gkLat = Math.floor(ep.lat / GRID_SIZE);
                const gkLon = Math.floor(ep.lon / GRID_SIZE);

                for(let dx=-2; dx<=2; dx++){
                    for(let dy=-2; dy<=2; dy++){
                        const cell = g.spatial.get(`${gkLat+dx},${gkLon+dy}`);
                        if(!cell) continue;
                        for(const candidate of cell){
                            if(!candidate.isTaxi) continue;
                            const d = haversineMeters(ep.lat, ep.lon, candidate.lat, candidate.lon);
                            if (d < minD) { minD = d; bestNodeId = candidate.id; }
                        }
                    }
                }

                if(bestNodeId){
                    g.nodes.get(bestNodeId).edges.push({ target: rwyNode.id, dist: minD });
                    rwyNode.edges.push({ target: bestNodeId, dist: minD });
                }
            }
        }

        graphs[icao] = g;
        self.postMessage({ type: 'READY', icao: icao, nodeCount: g.nodes.size });
    }
    else if (action === 'CALC') {
        const results = {};
        const { flights, activeRunwaysMap } = payload;

        flights.forEach(f => {
            const icao = f.depIcao;
            const g = graphs[icao];
            if (!g) return;

            // Aktive Runways
            const currentActiveRunways = activeRunwaysMap[icao] || [];

            // Kandidaten sammeln
            // Wenn keine aktiven Runways bekannt sind, nehmen wir ALLE.
            const designsToCheck = (currentActiveRunways.length > 0)
                ? currentActiveRunways
                : Array.from(g.runwayNodes.keys());

            const targets = [];
            const targetIds = new Set();

            // --- HEADING FILTER ---
            // Wenn der Flieger rollt (gs > 5), filtern wir Runways, die "im Rücken" liegen.
            const useHeadingFilter = (f.gs > 5);

            for (const des of designsToCheck) {
                // Heading-Check für diese Runway
                if (useHeadingFilter) {
                    const rwyHdg = getRunwayHeading(des);
                    if (rwyHdg !== null) {
                        const diff = Math.abs(angleDiff(f.hdg, rwyHdg));
                        // Wenn der Winkel > 100 Grad ist, rollen wir in die entgegengesetzte Richtung.
                        // Wir schließen diese Runway aus.
                        if (diff > 100) continue;
                    }
                }

                const nodes = g.runwayNodes.get(des);
                if (nodes) {
                    for (const nid of nodes) {
                        targets.push({id: nid, des: des});
                        targetIds.add(nid);
                    }
                }
            }

            // Fallback: Wenn wir durch den Heading-Filter ALLES gelöscht haben (z.B. bei Pushback quer zur Bahn),
            // nehmen wir wieder alle aus 'designsToCheck'.
            if (targets.length === 0) {
                 for (const des of designsToCheck) {
                    const nodes = g.runwayNodes.get(des);
                    if(nodes) for(const nid of nodes) { targets.push({id: nid, des: des}); targetIds.add(nid); }
                 }
            }
            if (targets.length === 0) return;


            // Startknoten finden
            let startNodeId = null;
            let minStartD = 800;

            const gkLat = Math.floor(f.lat / GRID_SIZE);
            const gkLon = Math.floor(f.lon / GRID_SIZE);

            for(let dx=-1; dx<=1; dx++){
                for(let dy=-1; dy<=1; dy++){
                    const cell = g.spatial.get(`${gkLat+dx},${gkLon+dy}`);
                    if(!cell) continue;
                    for(const node of cell){
                        if(!node.isTaxi) continue;
                        if(Math.abs(f.lat - node.lat) > 0.01) continue;
                        const d = haversineMeters(f.lat, f.lon, node.lat, node.lon);
                        if (d < minStartD) { minStartD = d; startNodeId = node.id; }
                    }
                }
            }

            if (!startNodeId) return;

            // Dijkstra
            const dists = new Map();
            const pq = new MinHeap();
            dists.set(startNodeId, 0);
            pq.push({ id: startNodeId, cost: 0 });

            let bestRwy = null;
            let bestDist = Infinity;
            let found = false;

            let ops = 0; const MAX_OPS = 8000;

            while(pq.size > 0 && ops++ < MAX_OPS){
                const u = pq.pop();

                if(u.cost > (dists.get(u.id) ?? Infinity)) continue;
                if(u.cost > bestDist) continue;

                if(targetIds.has(u.id)){
                    const tObj = targets.find(t => t.id === u.id);
                    if(tObj){
                        if(u.cost < bestDist){
                            bestDist = u.cost;
                            bestRwy = tObj.des;
                            found = true;
                        }
                    }
                }

                const uNode = g.nodes.get(u.id);
                if(uNode){
                    for(const edge of uNode.edges){
                        const alt = u.cost + edge.dist;
                        if(alt < (dists.get(edge.target) ?? Infinity)){
                            dists.set(edge.target, alt);
                            pq.push({ id: edge.target, cost: alt });
                        }
                    }
                }
            }

            if(found) {
                const totalDist = bestDist + minStartD;
                const timeSec = (totalDist / TAXI_SPEED_MS) + RUNWAY_ENTRY_DELAY;
                results[f.cid] = { time: Math.round(timeSec), dist: Math.round(totalDist), rwy: bestRwy };
            }
        });

        self.postMessage({ type: 'RESULT', data: results });
    }
};
