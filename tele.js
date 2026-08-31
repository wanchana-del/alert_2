let isLineSent = false; //ตัวล็อกไลน์ไม่ให้ส่งจนเกินไป

// 📸 ระบบแคปหน้าจอ (บังคับแนวนอน Desktop)
const captureAndSendLine = async (stationName, level) => {
    if (isLineSent) return; 
    isLineSent = true; 

    showFetchDebugBadge(`📸 กำลังจัดเฟรมภาพและแคปหน้าจอ...`, false);

    const mapContainer = document.getElementById('map'); 
    const originalCssText = mapContainer.style.cssText;
    
    let originalCenter, originalZoom;
    if (map) {
        originalCenter = map.getCenter();
        originalZoom = map.getZoom();
    }

    try {
        // บังคับขยายจอเป็นแนวนอน 1280x720 ชั่วคราว
        mapContainer.style.position = 'fixed';
        mapContainer.style.top = '0';
        mapContainer.style.left = '0';
        mapContainer.style.width = '1280px';
        mapContainer.style.height = '720px';
        mapContainer.style.zIndex = '9999';

        if (map) {
            map.invalidateSize({ pan: false });
            // เล็งกล้องไปตรงกลางแม่น้ำ 
            map.setView([14.260, 101.280], 12.450, { animate: false }); 
        }

        // รอโหลดภาพแผนที่ให้ชัด
        await new Promise(r => setTimeout(r, 1500)); 

        const canvas = await html2canvas(mapContainer, { 
            useCORS: true,
            width: 1280,
            height: 720,
            scale: 1 
        });
        
        const base64Image = canvas.toDataURL("image/png");

        // กลับคืนสภาพเดิม
        mapContainer.style.cssText = originalCssText;
        if (map) {
            map.invalidateSize({ pan: false });
            map.setView(originalCenter, originalZoom, { animate: false });
        }

        // รูปเข้า PHP
        const formData = new FormData();
        formData.append('image', base64Image);
        formData.append('message', `\n🚨 แจ้งเตือนวิกฤต!\nสถานี: ${stationName}\nระดับน้ำ: ${level.toFixed(3)} ม.\nให้รีบเฝ้าระวังทันที!`);

        await fetch('line_notify.php', { method: 'POST', body: formData });

        showFetchDebugBadge(`✅ ส่งรูปแจ้งเตือนเข้า LINE สำเร็จ!`, false);
        
        // คูลดาวน์ 30 นาที
        setTimeout(() => { isLineSent = false; }, 30 * 60 * 1000);

    } catch (e) {
        console.error("แคปจอพัง:", e);
        mapContainer.style.cssText = originalCssText;
        if (map) {
            map.invalidateSize({ pan: false });
            map.setView(originalCenter, originalZoom, { animate: false });
        }
        showFetchDebugBadge(`❌ ส่ง LINE ไม่สำเร็จ`, true);
        isLineSent = false; 
    }
};

// คำนวณเวลาเดินทางน้ำแบบ 2 ท่อน (รวมระยะ 13 กม.)
const getDynamicLagTime = () => {
    const st15 = stations.find(s => s.id === "ST.15"); // ต้นทาง (วังตะไคร้)
    
    if (!st15 || !st15.flowRate || st15.flowRate <= 0) {
        return " (คำนวณไม่ได้: ขาดข้อมูล Q)";
    }

    const Q = st15.flowRate; 

    // อย่าลืมแก้เลข Area ตรงนี้ให้ตรงกับของจริง
    const AREA_ST15 = 120.5; 
    const AREA_NY1B = 250.0; 

    const V1_m_s = Q / AREA_ST15;                  
    const T1_hours = 6.5 / (V1_m_s * 3.6);         

    const V2_m_s = Q / AREA_NY1B;                  
    const T2_hours = 6.5 / (V2_m_s * 3.6);         

    let totalTimeHours = T1_hours + T2_hours;

    if (totalTimeHours > 24) totalTimeHours = 24; 
    if (totalTimeHours < 1) totalTimeHours = 1;

    return totalTimeHours.toFixed(1); 
};

// CSS แยกสำหรับกล่องป้ายเล็ก
const extraCSS = document.createElement('style');
extraCSS.innerHTML = `
    .mini-grey-tooltip {
        padding: 2px 6px !important; 
        background: rgba(255, 255, 255, 0.85) !important;
        border: 1px solid #bdc3c7 !important;
        box-shadow: 0 1px 3px rgba(0,0,0,0.2) !important;
        border-radius: 4px !important;
    }
    .khundan-corner.leaflet-tooltip-right::before {
        top: 10px !important;
        bottom: auto !important;
        margin-top: 0 !important;
    }
`;
document.head.appendChild(extraCSS);

const alertSound = new Audio('tithuh.mp3');

if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
    Notification.requestPermission().catch(() => {});
}

const mainStationIds = ["ST.00", "ST.01", "ST.13", "ST.14", "ST.15", "ST.16"];
const syncTargetIds_NY1B = ["ST.02", "ST.03","ST.06" ];

const VALIDATION_RULES = {
    "ST.00": { min: 0, max: 100, maxChangePerRead: 5.0 }, 
    "ST.01": { min: 0, max: 15, maxChangePerRead: 1.5 },
    "ST.13": { min: 0, max: 15, maxChangePerRead: 1.5 },
    "ST.14": { min: 0, max: 40, maxChangePerRead: 3.0 },
    "ST.15": { min: 0, max: 40, maxChangePerRead: 3.0 },
    "ST.16": { min: 0, max: 40, maxChangePerRead: 3.0 }
};

// 🔴 ค่าเริ่มต้น (fallback) เผื่อดึงจากเซิร์ฟเวอร์ไม่สำเร็จ - ค่าจริงจะถูกอัปเดตอัตโนมัติจากที่ตั้งไว้ในหน้า admin.php
const ALERT_LEVELS = {
    "ST.00": { warn: 80.00, crit: 100.00 }, 
    "ST.14": { warn: 26.00, crit: 27.00 },
    "ST.15": { warn: 29.50, crit: 30.50 },
    "ST.16": { warn: 17.50, crit: 18.50 },
    "ST.01": { warn: 7.50,  crit: 8.00  },
    "ST.13": { warn: 6.00,  crit: 6.25  }
};

const STATION_CS_DATA = {
    "ST.14": { n_id: 51, t: 28.418, b: 23.073 }, 
    "ST.15": { n_id: 52, t: 32.242, b: 24.900 }, 
    "ST.16": { n_id: 53, t: 19.340, b: 15.000 }, 
    "ST.01": { n_id: 61, t: 11.291, b: 3.111 },  
    "ST.13": { n_id: 62, t: 8.919,  b: -1.945 }  
};

let map = null;

const getAlertThresholds = (stationId) => {
    if (syncTargetIds_NY1B.includes(stationId)) return ALERT_LEVELS["ST.01"];
    return ALERT_LEVELS[stationId] || { warn: 99.9, crit: 100.0 };
};

const STALE_THRESHOLD_MS = 20 * 60 * 1000; 
const consecutiveFailsByStation = {}; 

const stations = [
    { id: "ST.00", name: "เขื่อนขุนด่านปราการชล", lat: 14.305897, lng: 101.327458, currentLevel: null, flowRate: 0, chartData: [], recordTime: null, marker: null, waveMarker: null, lastUpdated: null },
    { id: "ST.01", name: "NY.1B", lat: 14.245769751328265, lng: 101.27427890038338, currentLevel: null, flowRate: 0, chartData: [], recordTime: null, marker: null, waveMarker: null, lastUpdated: null },
    { id: "ST.02", name: "บ้านหนองโพธิ์", lat: 14.210300, lng: 101.251217, currentLevel: null, flowRate: 0, chartData: [], recordTime: null, marker: null, waveMarker: null, lastUpdated: null },
    { id: "ST.03", name: "บ้านไร่ ต.บ้านใหม่", lat: 14.208875, lng: 101.242958, currentLevel: null, flowRate: 0, chartData: [], recordTime: null, marker: null, waveMarker: null, lastUpdated: null },
    { id: "ST.04", name: "บ้านคีรีวัน ต.หินตั้ง", lat: 14.246447, lng: 101.274892, currentLevel: null, flowRate: 0, chartData: [], recordTime: null, marker: null, waveMarker: null, lastUpdated: null },
    { id: "ST.05", name: "บ้านวังดอกไม้ ต.สาริกา", lat: 14.234028, lng: 101.267083, currentLevel: null, flowRate: 0, chartData: [], recordTime: null, marker: null, waveMarker: null, lastUpdated: null },
    { id: "ST.06", name: "บ้านบุ่ง ต.สาริกา", lat: 14.219028, lng: 101.259433, currentLevel: null, flowRate: 0, chartData: [], recordTime: null, marker: null, waveMarker: null, lastUpdated: null },
    { id: "ST.07", name: "บ้านกุดรัง ต.สาริกา", lat: 14.264731, lng: 101.277047, currentLevel: null, flowRate: 0, chartData: [], recordTime: null, marker: null, waveMarker: null, lastUpdated: null },
    { id: "ST.08", name: "บ้านสีเสียด ต.หินตั้ง", lat: 14.262336, lng: 101.280953, currentLevel: null, flowRate: 0, chartData: [], recordTime: null, marker: null, waveMarker: null, lastUpdated: null },
    { id: "ST.09", name: "บ้านวังยายฉิม ต.หินตั้ง", lat: 14.271558, lng: 101.285069, currentLevel: null, flowRate: 0, chartData: [], recordTime: null, marker: null, waveMarker: null, lastUpdated: null },
    { id: "ST.10", name: "บ้านวังยาว ต.หินตั้ง", lat: 14.276158, lng: 101.287453, currentLevel: null, flowRate: 0, chartData: [], recordTime: null, marker: null, waveMarker: null, lastUpdated: null },
    { id: "ST.11", name: "บ้านท่าชัย ต.หินตั้ง", lat: 14.287664, lng: 101.292042, currentLevel: null, flowRate: 0, chartData: [], recordTime: null, marker: null, waveMarker: null, lastUpdated: null },
    { id: "ST.12", name: "บ้านท่าด่าน ต.หินตั้ง", lat: 14.301244, lng: 101.305758, currentLevel: null, flowRate: 0, chartData: [], recordTime: null, marker: null, waveMarker: null, lastUpdated: null },
    { id: "ST.13", name: "NY.7 แม่น้ำนครนายก", lat: 14.200489, lng: 101.219450, currentLevel: null, flowRate: 0, chartData: [], recordTime: null, marker: null, waveMarker: null, lastUpdated: null },
    { id: "ST.14", name: "สถานีนางรอง", lat: 14.315175, lng: 101.312772, currentLevel: null, flowRate: 0, chartData: [], recordTime: null, marker: null, waveMarker: null, lastUpdated: null },
    { id: "ST.15", name: "สถานีวังตระไคร้", lat: 14.321322, lng: 101.306047, currentLevel: null, flowRate: 0, chartData: [], recordTime: null, marker: null, waveMarker: null, lastUpdated: null },
    { id: "ST.16", name: "สถานีสาริกา", lat: 14.286679, lng: 101.277033, currentLevel: null, flowRate: 0, chartData: [], recordTime: null, marker: null, waveMarker: null, lastUpdated: null }
];

const localCache = JSON.parse(localStorage.getItem('water_level_cache')) || {};
stations.forEach(st => {
    if (localCache[st.id]) {
        st.currentLevel = localCache[st.id].level;
        st.flowRate = localCache[st.id].flowRate || 0;
        st.lastUpdated = new Date(localCache[st.id].time);
        st.recordTime = localCache[st.id].recordTime || null;
    }
});

const getTooltipConfig = (id) => {
    if (id === "ST.00") return { dir: 'right', off: [15, 25], align: 'left' };
    if (id === "ST.02") return { dir: 'right', off: [15, 7], align: 'center' };
    if (id === "ST.03") return { dir: 'bottom', off: [0, 20], align: 'center' };
    if (id === "ST.13") return { dir: 'left', off: [-30, 0], align: 'left' };
    if (id === "ST.01" || id === "ST.07" || id === "ST.11" || id === "ST.14" || id === "ST.10") {
        return { dir: 'right', off: [45, 0], align: 'left' };
    }
    return { dir: 'left', off: [-45, 0], align: 'right' };
};

const getWaitingIcon = (stationId, isMain) => {
    const size = isMain ? 20 : 16;
    const offset = isMain ? 0 : 2;
    const bgColor = (stationId === "ST.01" || stationId === "ST.13" || stationId === "ST.00") ? "#00a8ff" : "#95a5a6";
    
    return L.divIcon({
        className: 'custom-div-icon',
        html: `<div style="width:${size}px; height:${size}px; background:${bgColor}; border-radius:50%; border:2px solid #ffffff; box-shadow:0 2px 6px rgba(0,0,0,0.6); margin:${offset}px; box-sizing:content-box;"></div>`,
        iconSize: [24, 24], iconAnchor: [12, 12]
    });
};

const getPulseIcon = (colorClass) => {
    return L.divIcon({
        className: 'custom-div-icon',
        html: `<div class='pulsing-dot ${colorClass}'></div>`,
        iconSize: [24, 24], iconAnchor: [12, 12]
    });
};

const getWaveIcon = (colorCode) => {
    return L.divIcon({
        className: 'wave-icon-wrapper',
        html: `
            <svg class="buffer-wave-container" width="600" height="600" viewBox="0 0 600 600" style="position: absolute; left: -288px; top: -288px;">
                <circle class="buffer-wave" cx="300" cy="300" r="90" fill="${colorCode}" stroke="${colorCode}" stroke-width="2" />
                <circle class="buffer-wave-inner" cx="300" cy="300" r="90" fill="${colorCode}" stroke="${colorCode}" stroke-width="2" />
            </svg>`,
        iconSize: [24, 24], iconAnchor: [12, 12]
    });
};

const staleSuffix = (station) => {
    if (!station.lastUpdated) return "";
    const ageMs = Date.now() - station.lastUpdated.getTime();
    return ageMs > STALE_THRESHOLD_MS ? " ⚠️" : "";
};

const loadRiver = (mapInstance) => {
    if (typeof omnivore === 'undefined') {
        console.warn("⚠️ ลืมแปะ script ของ leaflet-omnivore แผนที่จะไม่มีแม่น้ำ");
        return;
    }

    omnivore.kml('hydro.kml?t=' + Date.now())
        .on('ready', function() {
            this.setStyle({
                color: '#00a8ff',    
                weight: 3,            
                opacity: 0.8          
            });
        })
        .addTo(mapInstance);
};

const checkWangTaKraiWarning = () => {
    const st15 = stations.find(s => s.id === "ST.15");
    if (!st15 || st15.currentLevel === null) return false;
    
    const th15 = getAlertThresholds("ST.15");
    if (st15.currentLevel >= th15.warn) return true;
    return false;
};

// 🔴 ตัวช่วยสร้างชุดข้อมูลกราฟ 2 เส้น: ระดับน้ำ (แกนซ้าย) + อัตราการไหล Q (แกนขวา)
// ใช้ร่วมกันทั้งกราฟเล็กในป๊อปอัปและกราฟใหญ่ในโมดัล จะได้ไม่ต้องแก้ 2 ที่ทุกครั้ง
const buildChartDatasets = (st, isBig) => {
    const labels = st.chartData.map(d => d.time);

    const levelData = st.chartData.map(d =>
        (d.level === null || d.level === undefined) ? null : d.level
    );
    const qData = st.chartData.map(d => d.q);

    const datasets = [
        {
            label: isBig ? 'ระดับน้ำ (ม.รทก.)' : 'ระดับน้ำ',
            data: levelData,
            borderColor: '#2980b9',
            backgroundColor: 'rgba(41, 128, 185, 0.18)',
            borderWidth: isBig ? 2.5 : 2,
            fill: true,
            pointRadius: isBig ? 3 : 1,
            pointHoverRadius: isBig ? 6 : 3,
            tension: 0.3,
            spanGaps: true,     // ถ้าบางช่วงไม่มีค่า ให้ลากเส้นข้ามไป ไม่ขาดตอน
            yAxisID: 'y'        // 🔴 ผูกกับแกนซ้าย
        },
        {
            label: isBig ? 'อัตราการไหล Q (ลบ.ม./วิ)' : 'Q (ลบ.ม./วิ)',
            data: qData,
            borderColor: '#e74c3c',
            backgroundColor: 'rgba(231, 76, 60, 0.12)',
            borderWidth: isBig ? 2 : 1.5,
            fill: false,
            pointRadius: isBig ? 2 : 0,
            pointHoverRadius: isBig ? 5 : 3,
            tension: 0.3,
            borderDash: [5, 3],  // ทำเป็นเส้นประ จะได้แยกออกจากเส้นระดับน้ำชัด ๆ
            yAxisID: 'y1'        // 🔴 ผูกกับแกนขวา (คนละหน่วยกับระดับน้ำ)
        }
    ];

    return { labels, datasets };
};

const getPopupHTML = (st, level, alertColor, gapText) => {
    let imageSection = "";
    const cs = STATION_CS_DATA[st.id];
    const th = getAlertThresholds(st.id);

    if (cs && level !== null) {
        const imgUrl = `proxy_image.php?node=${cs.n_id}&t=${cs.t}&b=${cs.b}&v=${level.toFixed(3)}&cri=${th.crit.toFixed(3)}&alt=${th.warn.toFixed(3)}`;
        imageSection = `
            <div style="margin-top: 10px; border-top: 1px solid #eee; padding-top: 10px;">
                <img src="${imgUrl}&time=${Date.now()}" 
                     onerror="this.style.display='none'" 
                     onclick="openImageModal(this.src)"
                     style="width: 100%; max-width: 300px; border-radius: 6px; box-shadow: 0 1px 4px rgba(0,0,0,0.2); cursor: zoom-in;" 
                     alt="หน้าตัดแม่น้ำ ${st.name}" 
                     title="คลิกเพื่อขยายดูบนหน้านี้">
            </div>
        `;
    }

    if (level === null) {
        return `<div style="text-align: center; font-family: 'Sarabun', sans-serif; min-width: 200px;">
                    <h4 style="margin: 0 0 10px 0; color: #2c3e50; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 5px;">${st.name.replace('สถานี', '')}</h4>
                    <span style="color: #7f8c8d; font-size: 14px;">ไม่มีข้อมูลเซ็นเซอร์</span>
                </div>`;
    }
    
    // 🔴 โลจิกคำนวณน้ำขึ้น-น้ำลง
    let trendHTML = "";
    if (st.level_15min_ago !== null && st.level_15min_ago !== undefined) {
        const diff = level - st.level_15min_ago;
        let arrow = "➖ ทรงตัว";
        let trendColor = "#7f8c8d"; // สีเทา
        
        if (diff > 0) {
            arrow = "🔺 น้ำขึ้น";
            trendColor = "#c0392b"; // สีแดง (อันตราย น้ำกำลังมา)
        } else if (diff < 0) {
            arrow = "🔻 น้ำลง";
            trendColor = "#27ae60"; // สีเขียว (ปลอดภัย น้ำลดลง)
        }
        
        trendHTML = `
            <div style="font-size: 14px; font-weight: bold; color: ${trendColor}; margin-top: 5px; background: #f0f3f4; padding: 4px; border-radius: 4px; border: 1px solid #bdc3c7;">
                ${arrow} ${Math.abs(diff).toFixed(3)} ม./ชม.
            </div>
        `;
    }

    let lagWarningHTML = "";
    if (st.id === "ST.01" && checkWangTaKraiWarning()) {
        lagWarningHTML = `
            <div style="background: #fff3cd; color: #856404; padding: 4px; border-radius: 4px; font-size: 12px; margin-top: 5px; border: 1px solid #ffeeba;">
                ⚠️ ระวัง! มวลน้ำจากวังตะไคร้จะมาถึงในอีก ~${getDynamicLagTime()} ชม.
            </div>`;
    }

    // 🔴 กล่องกราฟ: แยก canvas ออกมาอยู่ในกล่องของตัวเอง
    // (Chart.js ยึดขนาดจากกล่องแม่ ถ้ามีหัวข้อปนอยู่ด้วยบางเบราว์เซอร์จะคำนวณความสูงเพี้ยน)
    let chartHTML = "";
    if (st.chartData && st.chartData.length > 0) {
        chartHTML = `
            <div style="width: 250px; margin-top: 10px; border-top: 1px solid #eee; padding-top: 10px;">
                <div style="font-size: 13px; font-weight: bold; color: #2c3e50; text-align: center; margin-bottom: 5px;">
                    📈 กราฟระดับน้ำ &amp; อัตราการไหล
                </div>
                <div style="width: 100%; height: 165px; position: relative;">
                    <canvas id="chart-${st.id}" onclick="openBigChart('${st.id}')" style="cursor: zoom-in;" title="คลิกเพื่อขยายกราฟ"></canvas>
                </div>
            </div>
        `;
    }

    const extraInfoHTML = syncTargetIds_NY1B.includes(st.id) ? "" : `
        <div style="font-size: 14px; margin-bottom: 5px; color: #333;">
            ระดับน้ำปัจจุบัน <span style="font-size: 18px; font-weight: bold; color: ${alertColor};">${level.toFixed(3)} เมตร</span>
        </div>
        <div style="font-size: 14px; color: #555; margin-bottom: 5px; font-weight: bold;">
            ${gapText}
        </div>
        ${trendHTML}
        ${chartHTML} <!-- 🔴 แปะกราฟตรงนี้ -->
       
        ${lagWarningHTML}
    `;
    
    return `
        <div style="text-align: center; min-width: 250px; font-family: 'Sarabun', sans-serif;">
            <h4 style="margin: 0 0 10px 0; color: #2c3e50; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 5px;">${st.name.replace('สถานี', '')}</h4>
            ${extraInfoHTML}
            ${imageSection}
        </div>
         <div style="font-size: 12px; color: #888; background: #f9f9f9; padding: 5px; border-radius: 4px; margin-top: 8px;">
            อัปเดตเมื่อ: ${st.recordTime || '-'}
        </div>
    `;
};

// ฟังก์ชันควบคุมการเปิดกล่องขยายรูป 
const openImageModal = (src) => {
    const modal = document.getElementById('imageModal');
    const modalImg = document.getElementById('modalImg');
    if (modal && modalImg) {
        modal.style.display = "flex";
        modalImg.src = src;
    }
};

// ฟังก์ชันสร้างหน้าต่าง Modal โชว์กราฟใหญ่แบบคมชัด
const openBigChart = (stId) => {
    const st = stations.find(s => s.id === stId);
    if (!st || !st.chartData || st.chartData.length === 0) return;

    const isMobile = window.innerWidth <= 768;

    // 1. สร้างฉากหลังสีดำๆ (Overlay)
    const modal = document.createElement('div');
    modal.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:99999; display:flex; justify-content:center; align-items:center; flex-direction:column;';
    
    // กดที่ฉากหลังสีดำให้ปิดหน้าต่าง
    modal.onclick = (e) => { 
        if (e.target === modal) document.body.removeChild(modal); 
    };

    // 2. ป้ายปุ่ม ปิดหน้าต่าง (X)
    const closeBtn = document.createElement('div');
    closeBtn.innerHTML = '❌ ปิดหน้าต่าง';
    closeBtn.style.cssText = 'color:white; cursor:pointer; margin-bottom:10px; font-family: "Sarabun", sans-serif; font-size:16px; align-self:flex-end; margin-right:10vw;';
    closeBtn.onclick = () => document.body.removeChild(modal);

    // 3. กรอบสีขาวสำหรับวาดกราฟ (บนมือถือให้กว้างขึ้นเพื่อให้หัวข้ออ่านง่าย)
    const container = document.createElement('div');
    container.style.cssText = `width:${isMobile ? '92vw' : '65vw'}; height:${isMobile ? '55vh' : '60vh'}; background:white; padding:${isMobile ? '12px' : '20px'}; border-radius:8px; box-shadow: 0 4px 15px rgba(0,0,0,0.5); box-sizing:border-box; text-align:center; position:relative;`;

    // 4. แคนวาสอันใหม่ (ห้ามตั้ง width/height ตรง ๆ ให้ canvas เด็ดขาด เดี๋ยว Chart.js จะวนลูปคำนวณขนาดจนบวมเต็มจอ ปล่อยให้ Chart.js จัดการเองผ่านขนาดของ container ที่กำหนดไว้แล้ว)
    const canvas = document.createElement('canvas');
    container.appendChild(canvas);
    
    modal.appendChild(closeBtn);
    modal.appendChild(container);
    document.body.appendChild(modal);

    // 5. สั่งวาดกราฟ Chart.js ไซส์ใหญ่ (2 เส้น 2 แกน)
    const { labels, datasets } = buildChartDatasets(st, true);

    new Chart(canvas, {
        type: 'line',
        data: { labels: labels, datasets: datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom',
                    labels: { font: { family: 'Sarabun', size: isMobile ? 11 : 14 } }
                },
                tooltip: {
                    titleFont: { family: 'Sarabun' },
                    bodyFont: { family: 'Sarabun' },
                    callbacks: {
                        // ใส่หน่วยต่อท้ายให้ชัด จะได้ไม่สับสนว่าเลขไหนคือระดับน้ำ เลขไหนคือ Q
                        label: (ctx) => {
                            const unit = ctx.datasetIndex === 0 ? ' ม.รทก.' : ' ลบ.ม./วิ';
                            return ` ${ctx.dataset.label}: ${Number(ctx.parsed.y).toFixed(3)}${unit}`;
                        }
                    }
                },
                title: {
                    display: true,
                    text: [`📈 กราฟระดับน้ำ & อัตราการไหล`, st.name], // 🔴 แยกชื่อสถานีเป็นอีกบรรทัด กันข้อความยาวจนถูกตัดบนมือถือ
                    align: 'center',
                    fullSize: true,
                    font: { size: isMobile ? 13 : 18, family: 'Sarabun', weight: 'bold' },
                    color: '#2c3e50',
                    padding: { top: 6, bottom: isMobile ? 10 : 16 }
                }
            },
            scales: {
                x: { ticks: { font: { family: 'Sarabun', size: isMobile ? 9 : 12 } } },
                y: {  // แกนซ้าย = ระดับน้ำ (ห้าม beginAtZero เดี๋ยวเส้นแบนติดพื้น)
                    position: 'left',
                    beginAtZero: false,
                    title: { display: !isMobile, text: 'ระดับน้ำ (ม.รทก.)', color: '#2980b9', font: { family: 'Sarabun', size: 12 } },
                    ticks: { font: { family: 'Sarabun', size: isMobile ? 9 : 12 }, color: '#2980b9' }
                },
                y1: { // แกนขวา = อัตราการไหล Q (คนละหน่วยกับระดับน้ำ)
                    position: 'right',
                    beginAtZero: true,
                    title: { display: !isMobile, text: 'Q (ลบ.ม./วิ)', color: '#e74c3c', font: { family: 'Sarabun', size: 12 } },
                    ticks: { font: { family: 'Sarabun', size: isMobile ? 9 : 12 }, color: '#e74c3c' },
                    grid: { drawOnChartArea: false } // ไม่ให้เส้นตารางซ้อนกันจนรก
                }
            }
        }
    });
};

const initMap = () => {
    try {
        if (typeof L === 'undefined') throw new Error("Leaflet โหลดไม่ขึ้น เช็กเน็ตด้วยครับ");

        // เพิ่ม zoomSnap กับ zoomDelta ตรงนี้
     map = L.map('map', { 
            preferCanvas: true,
            zoomSnap: 0.05,  
            zoomDelta: 0.05  
        }).setView([14.255, 101.295], 12.4); // ล็อกพิกัดตรงกลางและระยะซูมให้เห็นครบทุกสถานี

        L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            attribution: 'Tiles &copy; Esri | wanchana'
        }).addTo(map);

        loadRiver(map);

        const selectEl = document.getElementById('stationSelect');
        if (selectEl) selectEl.innerHTML = '';

        stations.forEach((st) => {
            if (selectEl && mainStationIds.includes(st.id)) {
                const option = document.createElement('option');
                option.value = st.id;
                option.text = st.name;
                selectEl.appendChild(option);
            }

            const conf = getTooltipConfig(st.id);
            const isMainOrSync = mainStationIds.includes(st.id) || syncTargetIds_NY1B.includes(st.id);
            const { warn: warnLevel, crit: critLevel } = getAlertThresholds(st.id);

            const isMainStation = mainStationIds.includes(st.id);
            const tooltipClass = st.id === "ST.00" ? 'minimal-tooltip khundan-corner' : (isMainStation ? 'minimal-tooltip' : 'mini-grey-tooltip');
            const nameFontSize = isMainStation ? '14px' : '11px';

            let initialLabel = ``;
            let popupContent = getPopupHTML(st, null, "#7f8c8d", "");
            let dotIcon = getWaitingIcon(st.id, isMainStation);
            let alertColor = "#7f8c8d";
            let dotClass = isMainStation ? "dot-main-default" : "dot-sync-default";

            if (isMainOrSync) {
                if (st.currentLevel !== null) {
                    let gapTextMap = "";
                    if (st.currentLevel < warnLevel) {
                        alertColor = "#27ae60"; dotClass = isMainStation ? "dot-main-green" : "dot-sync-green";
                        gapTextMap = `(อีก ${(warnLevel - st.currentLevel).toFixed(3)} ม.ร.สม ถึงเฝ้าระวัง)`;
                    } else if (st.currentLevel >= warnLevel && st.currentLevel <= critLevel) {
                        alertColor = "#f39c12"; dotClass = isMainStation ? "dot-main-orange" : "dot-sync-orange";
                        gapTextMap = `(อีก ${(critLevel - st.currentLevel).toFixed(3)} ม.ร.สม ถึงวิกฤต)`;
                    } else {
                        alertColor = "#c0392b"; dotClass = isMainStation ? "dot-main-red" : "dot-sync-red";
                        gapTextMap = `(เข้าสู่จุดวิกฤตมาเเล้ว ${(st.currentLevel - critLevel).toFixed(3)} ม.ร.สม 🚨)`;
                    }

                    const timeLabel = st.recordTime ? `<span style="color:#34495e; font-size:11px; font-weight:normal; display:block; text-align:${conf.align};">${st.recordTime}</span>` : '';
                    
                    let lagTooltipHTML = "";
                    if (st.id === "ST.01" && checkWangTaKraiWarning()) {
                        lagTooltipHTML = `<span style="color:#d35400; font-size:11px; font-weight:bold; display:block; text-align:${conf.align};">⚠️ น้ำวังตะไคร้มาใน ~${getDynamicLagTime()} ชม.</span>`;
                    }

                    initialLabel = syncTargetIds_NY1B.includes(st.id) ? "" : `
                        <span style="color:${alertColor}; font-size:13px; font-weight:bold; display:block; text-align:${conf.align};">${st.currentLevel.toFixed(3)} ม.${staleSuffix(st)}</span>
                        <span style="font-size:11px; color:#555; display:block; text-align:${conf.align};">${gapTextMap}</span>
                        ${timeLabel}
                        ${lagTooltipHTML}
                    `;
                    
                    popupContent = getPopupHTML(st, st.currentLevel, alertColor, gapTextMap);
                    dotIcon = getPulseIcon(dotClass);

                    if (isMainStation) {
                        st.waveMarker = L.marker([st.lat, st.lng], { icon: getWaveIcon(alertColor), interactive: false }).addTo(map);
                    }
                }
            }

            st.marker = L.marker([st.lat, st.lng], { icon: dotIcon, zIndexOffset: 1000 }).addTo(map);

            st.marker.bindTooltip(`
                <span style="font-size:${nameFontSize}; display:block; text-align:${conf.align}; color:#2c3e50; font-weight:bold;">${st.name}</span>
                ${initialLabel}
            `, { permanent: true, direction: conf.dir, className: tooltipClass, offset: conf.off });

            st.marker.bindPopup(popupContent);

            st.marker.on('click', () => {
                if (selectEl && isMainStation) {
                    selectEl.value = st.id;
                    updateSidebar();
                    map.panTo([st.lat, st.lng]);
                }
            });
        });
    } catch (error) {
        console.error("[Map Error]:", error);
        alert(`🚨 ระบบแผนที่พัง: ${error.message}`);
    }
};

const resetStationOnMap = (stationId) => {
    const st = stations.find(s => s.id === stationId);
    if (!st) return;

    st.currentLevel = null;

    const currentCache = JSON.parse(localStorage.getItem('water_level_cache')) || {};
    if (currentCache[stationId]) {
        delete currentCache[stationId];
        localStorage.setItem('water_level_cache', JSON.stringify(currentCache));
    }

    const conf = getTooltipConfig(st.id);
    const isMainStation = mainStationIds.includes(st.id);
    const nameFontSize = isMainStation ? '14px' : '11px'; 
    const dotIcon = getWaitingIcon(st.id, isMainStation);
    
    st.marker.setTooltipContent(`
        <span style="font-size:${nameFontSize}; display:block; text-align:${conf.align}; color:#2c3e50; font-weight:bold;">${st.name}</span>
    `);
    
    st.marker.setPopupContent(getPopupHTML(st, null, "#7f8c8d", "")); 
    st.marker.setIcon(dotIcon);

    if (st.waveMarker) {
        map.removeLayer(st.waveMarker);
        st.waveMarker = null;
    }

    if (stationId === "ST.01") {
        syncTargetIds_NY1B.forEach(childId => resetStationOnMap(childId));
    }

    updateSidebar(); 
};

const updateStationOnMap = (stationId, realLevel, recordTime, level15minAgo) => {
    const st = stations.find(s => s.id === stationId);
    if (!st) return;

    st.currentLevel = realLevel;
    st.lastUpdated = new Date();
    st.recordTime = recordTime || st.recordTime;
    
  if (level15minAgo !== undefined && level15minAgo !== null) {
        st.level_15min_ago = level15minAgo; 
        
        // 🔴 ระบบตรวจจับกราฟเชิดหัว (น้ำพุ่งฉับพลัน)
        const diff = realLevel - level15minAgo;
        
        // กำหนดเกณฑ์: ถ้าน้ำขึ้นเกิน 0.3 เมตร ภายใน 15 นาที (ปรับเลข 0.3 ได้ตามสถิติของกรมชลฯ)
        const spikeThreshold = 0.3; 

        if (diff >= spikeThreshold && mainStationIds.includes(stationId)) {
            console.warn(`🚨 กราฟพุ่งผิดปกติที่ ${st.name}! (+${diff.toFixed(3)} ม.)`);
            
            // บังคับเล่นเสียงแจ้งเตือน
            alertSound.play().catch(() => {});
            
            // โยนเข้าฟังก์ชันแคปจอส่ง LINE ทันที พร้อมแปะป้ายกำกับว่าน้ำพุ่ง
            captureAndSendLine(`${st.name} 📈 (น้ำขึ้นฉับพลัน +${diff.toFixed(3)} ม.)`, realLevel);
        }
    }

    const currentCache = JSON.parse(localStorage.getItem('water_level_cache')) || {};
    currentCache[stationId] = { 
        level: realLevel, 
        flowRate: st.flowRate,
        time: st.lastUpdated.toISOString(), 
        recordTime: st.recordTime,
        level_15min_ago: st.level_15min_ago 
    };
    localStorage.setItem('water_level_cache', JSON.stringify(currentCache));

    const { warn: warnLevel, crit: critLevel } = getAlertThresholds(stationId);
    const isMainStation = mainStationIds.includes(st.id);
    const nameFontSize = isMainStation ? '14px' : '11px'; 

    let alertColor = "#27ae60";
    let dotClass = isMainStation ? "dot-main-green" : "dot-sync-green";
    let gapTextMap = "";

    if (realLevel < warnLevel) {
        alertColor = "#27ae60";
        dotClass = isMainStation ? "dot-main-green" : "dot-sync-green";
        gapTextMap = `(อีก ${(warnLevel - realLevel).toFixed(3)} ม.ร.สม ถึงเฝ้าระวัง)`;
    } else if (realLevel >= warnLevel && realLevel <= critLevel) {
        alertColor = "#f39c12";
        dotClass = isMainStation ? "dot-main-orange" : "dot-sync-orange";
        gapTextMap = `(อีก ${(critLevel - realLevel).toFixed(3)} ม.ร.สม ถึงวิกฤต)`;
    } else {
        alertColor = "#c0392b";
        dotClass = isMainStation ? "dot-main-red" : "dot-sync-red";
        gapTextMap = `(เข้าสู่จุดวิกฤตมาเเล้ว ${(realLevel - critLevel).toFixed(3)} ม. 🚨)`;

        if (isMainStation) {
            alertSound.play().catch(e => {
                console.warn("รอคลิกเว็บก่อนเล่นเสียง:", e.message);
                notifyFallback(`🚨 วิกฤต! ${st.name}`, `ระดับน้ำ ${realLevel.toFixed(3)} ม.ร.สม เกินค่าวิกฤต`);
            });
            captureAndSendLine(st.name, realLevel);
        }
    }

    const conf = getTooltipConfig(st.id);
    const timeLabel = st.recordTime ? `<span style="color:#34495e; font-size:11px; font-weight:normal; display:block; text-align:${conf.align};">${st.recordTime}</span>` : '';
    
    let lagTooltipHTML = "";
    if (st.id === "ST.01" && checkWangTaKraiWarning()) {
        lagTooltipHTML = `<span style="color:#d35400; font-size:11px; font-weight:bold; display:block; text-align:${conf.align};">⚠️ น้ำวังตะไคร้มาใน ~${getDynamicLagTime()} ชม.</span>`;
    }

    const extraTooltipHTML = syncTargetIds_NY1B.includes(st.id) ? "" : `
        <span style="color:${alertColor}; font-size:13px; font-weight:bold; display:block; text-align:${conf.align};">${realLevel.toFixed(3)} ม.${staleSuffix(st)}</span>
        <span style="font-size:11px; color:#555; display:block; text-align:${conf.align};">${gapTextMap}</span>
        ${timeLabel}
        ${lagTooltipHTML}
    `;

    st.marker.setTooltipContent(`
        <span style="font-size:${nameFontSize}; display:block; text-align:${conf.align}; color:#2c3e50; font-weight:bold;">${st.name}</span>
        ${extraTooltipHTML}
    `);
    
    st.marker.setPopupContent(getPopupHTML(st, realLevel, alertColor, gapTextMap)); 
    st.marker.setIcon(getPulseIcon(dotClass));

    if (isMainStation) {
        if (st.waveMarker) {
            st.waveMarker.setIcon(getWaveIcon(alertColor));
        } else {
            st.waveMarker = L.marker([st.lat, st.lng], { icon: getWaveIcon(alertColor), interactive: false }).addTo(map);
        }
    }

    if (stationId === "ST.01") {
        syncTargetIds_NY1B.forEach(childId => updateStationOnMap(childId, realLevel, recordTime));
    }

    updateSidebar();
};

const updateSidebar = () => {
    const selectEl = document.getElementById('stationSelect');
    const statusBox = document.getElementById('statusDisplay');
    if (!selectEl || !statusBox) return;

    const st = stations.find(s => s.id === selectEl.value);
    if (!st) return;

    if (st.currentLevel === null) {
        statusBox.className = "status-box";
        const msg = st.lastUpdated ? 'ไม่มีข้อมูล / ขัดข้อง' : 'กำลังดึงข้อมูล...';
        statusBox.innerHTML = `สถานะ: ${msg}`;
        return;
    }

    const { warn: warnLevel, crit: critLevel } = getAlertThresholds(st.id);
    const timeDisplay = st.recordTime ? st.recordTime : st.lastUpdated.toLocaleTimeString('th-TH');
    const staleNote = `<div style="font-size:12px; font-weight:normal; opacity:0.9; margin-top:6px;">${timeDisplay}${staleSuffix(st)}</div>`;

    let gapText = "";
    
    if (st.currentLevel < warnLevel) {
        gapText = `อีก ${(warnLevel - st.currentLevel).toFixed(3)} ม. ถึงเฝ้าระวัง (เหลือง)`;
        statusBox.className = "status-box status-green";
        statusBox.innerHTML = `สถานะ: ปกติ (${st.currentLevel.toFixed(3)} ม.ร.สม)<br><span style="font-size:14px; font-weight:normal; display:block; margin-top:5px; color:#e8f8f5;"> ${gapText}</span>${staleNote}`;
    } else if (st.currentLevel >= warnLevel && st.currentLevel <= critLevel) {
        gapText = `อีก ${(critLevel - st.currentLevel).toFixed(3)} ม.ร.สม ถึงวิกฤต (แดง)`;
        statusBox.className = "status-box status-orange";
        statusBox.innerHTML = `สถานะ: เฝ้าระวัง (${st.currentLevel.toFixed(3)} ม.ร.สม)<br><span style="font-size:14px; font-weight:bold; display:block; margin-top:5px; color:#fff;">⚠️ ${gapText}</span>${staleNote}`;
    } else {
        gapText = `ทะลุจุดวิกฤตมาแล้ว ${(st.currentLevel - critLevel).toFixed(3)} ม.ร.สม!`;
        statusBox.className = "status-box status-red";
        statusBox.innerHTML = `สถานะ: วิกฤต<br>ระบายน้ำ! (${st.currentLevel.toFixed(3)} ม.)<br><span style="font-size:14px; font-weight:bold; display:block; margin-top:5px; color:#fff;">🚨 ${gapText}</span>${staleNote}`;
    }
};

const notifyFallback = (title, body) => {
    try {
        if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
            new Notification(title, { body });
        }
    } catch (e) {
        console.warn("Notification ใช้งานไม่ได้:", e.message);
    }
};

let badgeTimeout = null; 
const showFetchDebugBadge = (message, isError) => {
    let badge = document.getElementById('fetchDebugBadge');
    if (!badge) {
        badge = document.createElement('div');
        badge.id = 'fetchDebugBadge';
        badge.style.cssText = 'position:fixed; bottom:10px; right:10px; z-index:9999; padding:8px 14px; border-radius:6px; font-size:12px; font-family:sans-serif; color:#fff; max-width:340px; box-shadow:0 2px 8px rgba(0,0,0,0.3); white-space:pre-line; transition: opacity 0.5s ease-out;';
        document.body.appendChild(badge);
    }
    
    badge.style.opacity = '1';
    badge.style.display = 'block';
    badge.style.background = isError ? '#c0392b' : '#27ae60';
    badge.textContent = `[${new Date().toLocaleTimeString('th-TH')}] ${message}`;

    clearTimeout(badgeTimeout);
    
    badgeTimeout = setTimeout(() => {
        badge.style.opacity = '0';
        setTimeout(() => {
            if (badge.style.opacity === '0') badge.style.display = 'none';
        }, 500);
    }, 5000);
};

const validateLevel = (stationId, realLevel) => {
    const st = stations.find(s => s.id === stationId);
    const rules = VALIDATION_RULES[stationId];

    if (isNaN(realLevel)) return { ok: false, reason: "ค่าที่ดึงได้ไม่ใช่ตัวเลข" };
    if (!rules) return { ok: true }; 

    if (realLevel < rules.min || realLevel > rules.max) {
        return { ok: false, reason: `ค่า ${realLevel} อยู่นอกช่วงที่เป็นไปได้ [${rules.min}, ${rules.max}]` };
    }

    if (st && st.currentLevel !== null && Math.abs(realLevel - st.currentLevel) > rules.maxChangePerRead) {
        return { ok: false, reason: `ค่ากระโดดผิดปกติ: ${st.currentLevel} → ${realLevel} ม.` };
    }

    return { ok: true };
};

const fetchWithTimeout = async (resource, options = {}) => {
    const { timeout = 36000 } = options; // เปลี่ยนจาก 8000 เป็น 15000 (15 วินาที) หรือ 20000 
    const controller = new AbortController();
    const id = setTimeout(() => controller.abort(), timeout);
    
    try {
        const response = await fetch(resource, {
            ...options,
            signal: controller.signal
        });
        clearTimeout(id);
        return response;
    } catch (error) {
        clearTimeout(id);
        // ดัก Error เมื่อถูกตัดการเชื่อมต่อเพราะหมดเวลา
        if (error.name === 'AbortError') {
            throw new Error("เซิร์ฟเวอร์ตอบสนองช้าเกินไป (Timeout)");
        }
        throw error;
    }
};

const fetchWaterData = async (stationId, n_id) => {
    if (n_id === 999) return; 

    try {
        const apiUrl = `api.php?n_id=${n_id}&st_id=${stationId}&t=${Date.now()}`; // 🔴 ส่ง st_id ไปด้วย เพื่อให้เซิร์ฟเวอร์ส่งค่าเฝ้าระวัง/วิกฤตของสถานีนี้กลับมา
        
        const response = await fetchWithTimeout(apiUrl);
        if (!response.ok) throw new Error(`HTTP ${response.status} จากเซิร์ฟเวอร์`);
        
        const data = await response.json();
        
        if (data.status !== "success") {
            throw new Error(data.message || "เซิร์ฟเวอร์แจ้งสถานะล้มเหลว");
        }

        if (data.level === null || data.level === undefined || data.level === "" || data.level === "null") {
            resetStationOnMap(stationId); 
            showFetchDebugBadge(`⚠️ [${stationId}] ไม่มีข้อมูล`, true);
            return; 
        }

        const realLevel = parseFloat(data.level); 
        const recordTime = data.record_time; 
        const level15minAgo = data.level_15min_ago !== null ? parseFloat(data.level_15min_ago) : null; 
        
        const st = stations.find(s => s.id === stationId);
        if (st) {
            st.flowRate = parseFloat(data.q) || 0; 
            st.chartData = data.chart_data || []; // 🔴 รับอาร์เรย์กราฟมาเก็บไว้ตรงนี้ (มีทั้ง level และ q)
        }

        // 🔴 อัปเดตค่าเฝ้าระวัง/วิกฤตจากเซิร์ฟเวอร์ (ถ้าแอดมินตั้งค่าไว้ผ่านหน้า admin.php)
        // ถ้าเซิร์ฟเวอร์ยังไม่มีค่านี้ (เช่นยังไม่ได้อัปเดต api.php) จะใช้ค่าเริ่มต้นใน ALERT_LEVELS ต่อไปตามปกติ
        if (data.thresholds && typeof data.thresholds.warn === 'number' && typeof data.thresholds.crit === 'number') {
            ALERT_LEVELS[stationId] = { warn: data.thresholds.warn, crit: data.thresholds.crit };
        }

        const check = validateLevel(stationId, realLevel);

        if (!check.ok) {
            resetStationOnMap(stationId); 
            showFetchDebugBadge(`⚠️ [${stationId}] ค่าเอ๋อ: ${check.reason}`, true);
            return;
        }

        updateStationOnMap(stationId, realLevel, recordTime, level15minAgo);
        
        if (stationId === "ST.15") {
            const st01 = stations.find(s => s.id === "ST.01");
            if (st01 && st01.currentLevel !== null) {
                updateStationOnMap("ST.01", st01.currentLevel, st01.recordTime);
            }
        }

        consecutiveFailsByStation[stationId] = 0; 
        showFetchDebugBadge(`✅ [${stationId}] = ${realLevel} ม.`, false);

    } catch (error) {
        consecutiveFailsByStation[stationId] = (consecutiveFailsByStation[stationId] || 0) + 1;
        const failCount = consecutiveFailsByStation[stationId];
        showFetchDebugBadge(`❌ [${stationId}] ล้มเหลว (${failCount}): ${error.message}`, true);
        
        resetStationOnMap(stationId); 
    }
};

const fetchAllStations = async () => {
    await fetchWaterData("ST.00", 0); 
    await fetchWaterData("ST.14", 51); 
    await fetchWaterData("ST.15", 52); 
    await fetchWaterData("ST.16", 53); 
    await fetchWaterData("ST.01", 61); 
    await fetchWaterData("ST.13", 62); 
};

document.addEventListener("DOMContentLoaded", () => {
    localStorage.removeItem('water_level_cache');
    initMap();

    // 🔴 อีเวนต์ดักจับตอนคลิกเปิด Popup เพื่อวาดกราฟ Chart.js (2 เส้น: ระดับน้ำ + Q)
    map.on('popupopen', function(e) {
        const popupNode = e.popup._contentNode;
        const canvas = popupNode.querySelector('canvas');
        
        if (canvas) {
            const stId = canvas.id.replace('chart-', '');
            const st = stations.find(s => s.id === stId);
            
            if (st && st.chartData && st.chartData.length > 0 && typeof Chart !== 'undefined') {
                // ลบกราฟเก่าทิ้งก่อน ป้องกันบั๊กกราฟซ้อนกันตอนกดคลิกรัวๆ
                let existingChart = Chart.getChart(canvas);
                if (existingChart) existingChart.destroy();

                // 🔴 ดึงชุดข้อมูล 2 เส้นจากฟังก์ชันกลาง (ใช้ร่วมกับกราฟใหญ่)
                const { labels, datasets } = buildChartDatasets(st, false);
                
                new Chart(canvas, {
                    type: 'line',
                    data: { labels: labels, datasets: datasets },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: {
                                display: true,
                                position: 'bottom',
                                labels: {
                                    boxWidth: 10,
                                    boxHeight: 8,
                                    padding: 6,
                                    font: { size: 9, family: 'Sarabun' }
                                }
                            },
                            tooltip: {
                                titleFont: { family: 'Sarabun', size: 10 },
                                bodyFont: { family: 'Sarabun', size: 10 },
                                callbacks: {
                                    label: (ctx) => {
                                        const unit = ctx.datasetIndex === 0 ? ' ม.รทก.' : ' ลบ.ม./วิ';
                                        return ` ${ctx.dataset.label}: ${Number(ctx.parsed.y).toFixed(3)}${unit}`;
                                    }
                                }
                            }
                        },
                        scales: {
                            x: { ticks: { font: { size: 9 }, maxTicksLimit: 5 } },
                            y: {  // แกนซ้าย = ระดับน้ำ (ห้าม beginAtZero เดี๋ยวเส้นแบนติดพื้น)
                                position: 'left',
                                beginAtZero: false,
                                ticks: { font: { size: 9 }, color: '#2980b9', maxTicksLimit: 5 },
                                grid: { color: 'rgba(0,0,0,0.05)' }
                            },
                            y1: { // แกนขวา = อัตราการไหล Q (คนละหน่วยกับระดับน้ำ)
                                position: 'right',
                                beginAtZero: true,
                                ticks: { font: { size: 9 }, color: '#e74c3c', maxTicksLimit: 4 },
                                grid: { drawOnChartArea: false } // ไม่ให้เส้นตารางซ้อนกันจนรก
                            }
                        }
                    }
                });
            }
        }
    });

    const stationSelect = document.getElementById('stationSelect');
    if (stationSelect) {
        stationSelect.addEventListener('change', () => {
            updateSidebar();
            const st = stations.find(s => s.id === stationSelect.value);
            if (st && map) map.panTo([st.lat, st.lng]);
        });
    }

    fetchAllStations();
    setInterval(fetchAllStations, 180000); 
    setInterval(updateSidebar, 60000); 
});

// "เน็ตหลุด" (ออฟไลน์)
window.addEventListener('offline', () => {
    console.warn("ไม่พบสัญญานอินเทอร์เน็ต");
    showFetchDebugBadge(` ไม่พบสัญญานอินเทอร์เน็ต กำลังรอสัญญาณอินเทอร์เน็ต...`, true);
});

// "เน็ตกลับมา" (ออนไลน์)
window.addEventListener('online', () => {
    console.log("พบสัญญานอินเทอร์เน็ต เตรียมรีเฟรช...");
    showFetchDebugBadge(`พบสัญญานอินเทอร์เน็ต กำลังรีเฟรชระบบ...`, false);
    
    setTimeout(() => {
        window.location.reload(); 
    }, 1500);
});
// ==========================================
// ระบบ Auto-Recovery และ Admin Config Sync
// ==========================================

// เช็คว่าแอดมินเพิ่งอัปเดตค่าเฝ้าระวัง/วิกฤตมาใหม่หรือเปล่า
let lastConfigCheckTime = 0;
const checkAdminConfigUpdate = async () => {
    try {
        // วิ่งไปขอเวลาอัปเดตไฟล์ล่าสุด
        const response = await fetch(`config_updated.txt?t=${Date.now()}`, { cache: 'no-store' });
        if (!response.ok) return;
        
        const timestamp = parseInt(await response.text(), 10);
        
        // ถ้าไม่เคยเช็ค ให้จำค่าแรกไว้
        if (lastConfigCheckTime === 0) {
            lastConfigCheckTime = timestamp;
            return;
        }

        // ถ้าเวลาไฟล์ใหม่กว่าที่จำไว้ แปลว่าแอดมินเพิ่งกดเซฟ -> รีเฟรชหน้าเพื่ออัปเดตกฎใหม่
        if (timestamp > lastConfigCheckTime) {
            console.log("🔄 พบการอัปเดตตั้งค่าจาก Admin... กำลังรีเฟรชระบบ");
            showFetchDebugBadge(`🔄 ระบบตั้งค่าเปลี่ยน... กำลังรีเฟรชแผนที่`, false);
            setTimeout(() => window.location.reload(), 1000);
        }
    } catch (e) {
    }
};

// เช็คความสมบูรณ์ของจุดสถานีบนแผนที่ (ถ้าจุดสีเทาค้าง ให้รีโหลด)
const checkAllStationsStatus = () => {
    let hasGrayStation = false;

    // เช็คเฉพาะสถานีหลักที่สำคัญ (ข้าม ST.00 ไป เพราะตั้งใจให้เป็น null)
    mainStationIds.forEach(id => {
        if (id === "ST.00") return; // 🔴 เพิ่มบรรทัดนี้เพื่อข้ามการเช็ก ST.00

        const st = stations.find(s => s.id === id);
        if (st) {
            const ageMs = st.lastUpdated ? (Date.now() - st.lastUpdated.getTime()) : Infinity;
            if (st.currentLevel === null || ageMs > STALE_THRESHOLD_MS) {
                hasGrayStation = true;
                console.warn(`⚠️ สถานี ${st.name} ข้อมูลขาดหายหรือออฟไลน์!`);
            }
        }
    });

    if (hasGrayStation) {
        showFetchDebugBadge(`⚠️ พบสถานีที่ข้อมูลไม่ขึ้น กำลังพยายามดึงใหม่...`, true);
        console.log("🔄 สั่งดึงข้อมูลทุกสถานีใหม่ทั้งหมด...");
        fetchAllStations(); // ลองฮึบดึงใหม่ก่อน

        // ถ้าอีก 10 วินาทียังเทาอยู่ ให้ F5 ทิ้งเลยเพื่อเคลียร์แคช
        setTimeout(() => {
            const stillGray = mainStationIds.some(id => {
                if (id === "ST.00") return false; // 🔴 ข้ามการเช็ก ST.00 ตรงนี้ด้วย
                const st = stations.find(s => s.id === id);
                return !st || st.currentLevel === null;
            });

            if (stillGray) {
                console.error("❌ ดึงซ่อมไม่สำเร็จ บังคับรีเฟรชหน้าเว็บ!");
                window.location.reload();
            }
        }, 10000); 
    }
};

// สั่งรันตัวเช็คทุกๆ 20 วินาที
setInterval(() => {
    checkAdminConfigUpdate();
    checkAllStationsStatus();
}, 20000);
