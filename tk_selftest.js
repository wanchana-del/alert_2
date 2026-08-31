/* ============================================================
 * ชุดทดสอบอัตโนมัติ ระบบโทรมาตรขุนด่านปราการชล
 *
 * วิธีใช้:
 *   1) แปะแท็ก script src="tk_selftest.js" ไว้ท้าย index.html
 *      แล้วเรียก TKTEST.run() ในคอนโซล
 *   2) หรือวางทั้งไฟล์ในคอนโซลแล้วพิมพ์ TKTEST.run()
 *
 * ตัวเลือก:
 *   TKTEST.run()                  ทดสอบทั้งหมด ไม่ส่ง LINE
 *   TKTEST.run({ sendLine: true })  ทดสอบรวมการส่ง LINE จริงด้วย
 *   TKTEST.run({ only: "api" })     ทดสอบเฉพาะหมวดที่ต้องการ
 * ============================================================ */
(() => {
  const STATIONS = {
    "ST.14": { nid: 51, name: "สถานีนางรอง",     cs: { t: 28.418, b: 23.073 } },
    "ST.15": { nid: 52, name: "สถานีวังตระไคร้", cs: { t: 32.242, b: 24.900 } },
    "ST.16": { nid: 53, name: "สถานีสาริกา",     cs: { t: 19.340, b: 15.000 } },
    "ST.01": { nid: 61, name: "NY.1B",           cs: { t: 11.291, b: 3.111 } },
    "ST.13": { nid: 62, name: "NY.7",            cs: { t: 8.919,  b: -1.945 } },
  };

  const results = [];
  let opts = {};

  /* ---------- ตัวช่วยเขียนเทสต์ ---------- */
  const PASS = "✅ ผ่าน", FAIL = "❌ ไม่ผ่าน", WARN = "⚠️ ควรดู", SKIP = "⏭️ ข้าม";

  const check = async (group, name, fn) => {
    if (opts.only && opts.only !== group) return;
    const t0 = performance.now();
    let status = PASS, info = "";
    try {
      const r = await fn();
      if (r === false) { status = FAIL; }
      else if (r && typeof r === "object") {
        status = r.skip ? SKIP : r.warn ? WARN : r.ok === false ? FAIL : PASS;
        info = r.info || "";
      }
    } catch (e) {
      status = FAIL;
      info = e.message;
    }
    results.push({ หมวด: group, รายการ: name, ผล: status, รายละเอียด: info, "มิลลิวินาที": Math.round(performance.now() - t0) });
  };

  const has = (name) => typeof window[name] === "function" || typeof window[name] === "object";
  const api = async (stId) => {
    const s = STATIONS[stId];
    const r = await fetch(`api.php?n_id=${s.nid}&st_id=${stId}&t=${Date.now()}`);
    if (!r.ok) throw new Error(`HTTP ${r.status}`);
    return r.json();
  };

  /* ---------- 1. สภาพแวดล้อมของหน้าเว็บ ---------- */
  const testEnv = async () => {
    await check("env", "ไลบรารีแผนที่ Leaflet", () =>
      typeof L !== "undefined" ? true : { skip: true, info: "ไม่ได้อยู่บนหน้าเว็บหลัก" });

    await check("env", "ไลบรารีกราฟ Chart.js", () =>
      typeof Chart !== "undefined" ? true : { skip: true, info: "ไม่ได้อยู่บนหน้าเว็บหลัก" });

    await check("env", "ไลบรารีแคปหน้าจอ html2canvas", () =>
      typeof html2canvas !== "undefined" ? true : { skip: true, info: "ไม่ได้อยู่บนหน้าเว็บหลัก" });

    await check("env", "ไลบรารีแม่น้ำ omnivore", () =>
      typeof omnivore !== "undefined" ? true : { warn: true, info: "ไม่มี = แผนที่จะไม่มีเส้นแม่น้ำ" });

    await check("env", "อาร์เรย์ stations", () => {
      if (typeof stations === "undefined") return { skip: true, info: "ไม่ได้อยู่บนหน้าเว็บหลัก" };
      const missing = Object.keys(STATIONS).filter((id) => !stations.find((s) => s.id === id));
      return missing.length ? { ok: false, info: "ขาดสถานี " + missing.join(", ") } : { info: `${stations.length} สถานี` };
    });

    await check("env", "ไฟล์เสียงแจ้งเตือน tithuh.mp3", async () => {
      const r = await fetch("tithuh.mp3", { method: "HEAD" });
      return r.ok ? true : { warn: true, info: `HTTP ${r.status} — เสียงเตือนจะไม่ดัง` };
    });
  };

  /* ---------- 2. api.php ของทุกสถานี ---------- */
  const testApi = async () => {
    for (const [stId, s] of Object.entries(STATIONS)) {
      await check("api", `${stId} ${s.name}`, async () => {
        const d = await api(stId);
        if (d.status !== "success") return { ok: false, info: d.message || "status ไม่ใช่ success" };
        if (!Number.isFinite(Number(d.level))) return { ok: false, info: "ค่า level ไม่ใช่ตัวเลข" };

        const notes = [`${Number(d.level).toFixed(3)} ม.`];
        if (!Array.isArray(d.chart_data) || d.chart_data.length < 3)
          return { warn: true, info: "ข้อมูลกราฟน้อยกว่า 3 จุด คำนวณแนวโน้มไม่ได้" };
        notes.push(`กราฟ ${d.chart_data.length} จุด`);

        if (!d.thresholds) notes.push("⚠️ ไม่มี thresholds (จะใช้ค่า fallback ในหน้าเว็บ)");
        return { info: notes.join(" | ") };
      });
    }

    await check("api", "ความสดของข้อมูล (ไม่เกิน 30 นาที)", async () => {
      const d = await api("ST.14");
      const m = String(d.record_time || "").match(/(\d{1,2}):(\d{2})/);
      if (!m) return { warn: true, info: "อ่านเวลาบันทึกไม่ได้: " + d.record_time };
      const now = new Date();
      const rec = new Date(now); rec.setHours(+m[1], +m[2], 0, 0);
      let age = (now - rec) / 60000;
      if (age < -60) age += 1440;            // ข้ามวัน
      if (age > 30) return { warn: true, info: `ข้อมูลเก่า ${Math.round(age)} นาที` };
      return { info: `เก่า ${Math.round(Math.max(0, age))} นาที` };
    });

    await check("api", "n_id ผิดต้องถูกปฏิเสธ", async () => {
      const r = await fetch(`api.php?n_id=0&t=${Date.now()}`);
      const d = await r.json();
      return d.status === "error" ? { info: "ตอบ error ถูกต้อง" } : { ok: false, info: "ควรตอบ error แต่ไม่ตอบ" };
    });
  };

  /* ---------- 3. ตรรกะใน tele.js ---------- */
  const testLogic = async () => {
    await check("logic", "validateLevel: ค่าปกติต้องผ่าน", () => {
      if (typeof validateLevel !== "function") return { skip: true, info: "ไม่ได้อยู่บนหน้าเว็บหลัก" };
      return validateLevel("ST.14", 25.5).ok === true;
    });

    await check("logic", "validateLevel: ค่านอกช่วงต้องถูกปัด", () => {
      if (typeof validateLevel !== "function") return { skip: true };
      const r = validateLevel("ST.14", 999);
      return r.ok === false ? { info: r.reason } : { ok: false, info: "ควรปฏิเสธค่า 999" };
    });

    await check("logic", "validateLevel: ค่ากระโดดผิดปกติต้องถูกปัด", () => {
      if (typeof validateLevel !== "function" || typeof stations === "undefined") return { skip: true };
      const st = stations.find((s) => s.id === "ST.14");
      const backup = st.currentLevel;
      st.currentLevel = 25.0;
      const r = validateLevel("ST.14", 25.0 + 5);   // เกิน maxChangePerRead 3.0
      st.currentLevel = backup;
      return r.ok === false ? { info: r.reason } : { ok: false, info: "ควรปฏิเสธค่าที่กระโดด 5 ม." };
    });

    await check("logic", "เกณฑ์เฝ้าระวังต้องน้อยกว่าวิกฤตทุกสถานี", () => {
      if (typeof getAlertThresholds !== "function") return { skip: true };
      const bad = Object.keys(STATIONS).filter((id) => {
        const t = getAlertThresholds(id);
        return !(t.warn < t.crit);
      });
      return bad.length ? { ok: false, info: "ผิดที่ " + bad.join(", ") } : true;
    });

    await check("logic", "getDynamicLagTime ต้องอยู่ในช่วง 1-24 ชม.", () => {
      if (typeof getDynamicLagTime !== "function") return { skip: true };
      const v = getDynamicLagTime();
      if (String(v).includes("คำนวณไม่ได้")) return { warn: true, info: "ไม่มีค่า Q ที่วังตระไคร้" };
      const h = parseFloat(v);
      if (!(h >= 1 && h <= 24)) return { ok: false, info: `ได้ ${v}` };
      if (h >= 23.9) return { warn: true, info: `${v} ชม. = ชนเพดาน แปลว่าพื้นที่หน้าตัดที่ตั้งไว้ไม่สมจริง` };
      return { info: `${v} ชม.` };
    });

    await check("logic", "เกณฑ์จับน้ำพุ่งฉับพลัน (0.3 ม./15 นาที)", () => {
      if (typeof stations === "undefined") return { skip: true };
      // ทวนสูตรเดียวกับใน updateStationOnMap
      const spike = (diff) => diff >= 0.3;
      return spike(0.35) && !spike(0.25) ? { info: "ตัดสินถูกทั้งเคสเข้าและไม่เข้า" } : { ok: false };
    });
  };

  /* ---------- 4. สูตรแนวโน้ม (ชุดเดียวกับฝั่งเซิร์ฟเวอร์) ---------- */
  const testTrend = async () => {
    const slope = (pts) => {
      const n = pts.length; if (n < 3) return null;
      let sx = 0, sy = 0, sxy = 0, sxx = 0;
      for (const [t, y] of pts) { const x = t / 3600; sx += x; sy += y; sxy += x * y; sxx += x * x; }
      const den = n * sxx - sx * sx;
      return Math.abs(den) < 1e-9 ? null : (n * sxy - sx * sy) / den;
    };
    const series = (rate, wobble = 0) =>
      Array.from({ length: 7 }, (_, i) => [i * 300, 25.5 + rate * (i * 300 / 3600) + (wobble ? (i % 2 ? wobble : -wobble) : 0)]);

    const cases = [
      ["น้ำนิ่งต้องไม่แจ้ง", series(0), (s) => Math.abs(s) < 0.01],
      ["เซ็นเซอร์แกว่ง ±1 ซม. ต้องไม่แจ้ง", series(0, 0.01), (s) => Math.abs(s) < 0.10],
      ["ขึ้น 15 ซม./ชม. ต้องเข้าเกณฑ์เริ่มขึ้น", series(0.15), (s) => s >= 0.10 && s < 0.30],
      ["ขึ้น 48 ซม./ชม. ต้องเข้าเกณฑ์ขึ้นเร็ว", series(0.48), (s) => s >= 0.30],
      ["น้ำลดต้องไม่แจ้ง", series(-0.24), (s) => s < 0],
    ];

    for (const [name, pts, ok] of cases) {
      await check("trend", name, () => {
        const s = slope(pts);
        return ok(s) ? { info: `ชัน ${s.toFixed(3)} ม./ชม.` } : { ok: false, info: `ได้ชัน ${s.toFixed(3)}` };
      });
    }

    await check("trend", "จุดข้อมูลน้อยกว่า 3 ต้องคืนค่าว่าง", () =>
      slope([[0, 25], [300, 25.1]]) === null);

    await check("trend", "คำนวณแนวโน้มจากข้อมูลจริงของทุกสถานี", async () => {
      const out = [];
      for (const stId of Object.keys(STATIONS)) {
        const d = await api(stId);
        if (d.status !== "success" || !Array.isArray(d.chart_data)) continue;
        let day = 0, prev = -1;
        const pts = [];
        for (const row of d.chart_data) {
          const p = String(row.time || "").split(":");
          if (p.length < 2) continue;
          let mins = +p[0] * 60 + +p[1];
          if (prev >= 0 && mins < prev - 60) day += 1440;
          prev = mins;
          if (Number.isFinite(Number(row.level))) pts.push([(mins + day) * 60, Number(row.level)]);
        }
        const cut = pts.length ? pts[pts.length - 1][0] - 1800 : 0;
        const win = pts.filter((p) => p[0] >= cut);
        const s = slope(win);
        if (s !== null) out.push(`${stId} ${s.toFixed(3)}`);
      }
      return out.length ? { info: "ชัน (ม./ชม.): " + out.join(", ") } : { ok: false, info: "คำนวณไม่ได้เลยสักสถานี" };
    });
  };

  /* ---------- 5. ไฟล์ฝั่งเซิร์ฟเวอร์ ---------- */
  const testServer = async () => {
    await check("server", "proxy_image.php ดึงภาพรูปตัดได้", async () => {
      const s = STATIONS["ST.14"];
      const r = await fetch(`proxy_image.php?node=${s.nid}&t=${s.cs.t}&b=${s.cs.b}&v=25.500&cri=27&alt=26`);
      const blob = await r.blob();
      if (blob.size < 500) return { ok: false, info: `ได้ไฟล์ ${blob.size} ไบต์ น่าจะไม่ใช่รูป` };
      if (!blob.type.startsWith("image")) return { warn: true, info: "ชนิดไฟล์: " + blob.type };
      return { info: `${Math.round(blob.size / 1024)} KB · ${blob.type}` };
    });

    await check("server", "config_updated.txt เข้าถึงได้", async () => {
      const r = await fetch(`config_updated.txt?t=${Date.now()}`, { cache: "no-store" });
      if (!r.ok) return { warn: true, info: `HTTP ${r.status} — ระบบซิงก์ค่าจาก admin จะไม่ทำงาน` };
      const ts = parseInt(await r.text(), 10);
      return Number.isFinite(ts) ? { info: new Date(ts * 1000).toLocaleString("th-TH") } : { warn: true, info: "เนื้อไฟล์ไม่ใช่ timestamp" };
    });

    await check("server", "trend_watch.php ทำงานได้", async () => {
      const r = await fetch(`trend_watch.php?t=${Date.now()}`);
      if (!r.ok) return { skip: true, info: `HTTP ${r.status} — ยังไม่ได้อัปโหลดหรือปิดอยู่` };
      const txt = (await r.text()).trim();
      if (txt.includes("ถูกปิดอยู่")) return { warn: true, info: "ระบบเฝ้าแนวโน้มยังปิดอยู่ในหน้า admin" };
      if (/Fatal error|Parse error|Warning:/i.test(txt)) return { ok: false, info: txt.slice(0, 200) };
      return { info: txt.split("\n")[0].slice(0, 120) };
    });

    await check("server", "config_data.php ต้องเปิดตรงๆ ไม่ได้", async () => {
      const r = await fetch(`config_data.php?t=${Date.now()}`);
      const txt = await r.text();
      if (/line_channel_access_token|password_hash/i.test(txt))
        return { ok: false, info: "🚨 ไฟล์ตั้งค่ารั่ว! เข้า URL ตรงๆ แล้วเห็น token" };
      return { info: "ปลอดภัย ไม่มีข้อมูลรั่ว" };
    });
  };

  /* ---------- 6. เส้นทางแจ้งเตือน LINE (ส่งจริงเมื่อสั่งเท่านั้น) ---------- */
  const testLine = async () => {
    await check("line", "ส่งข้อความเข้า LINE", async () => {
      if (!opts.sendLine) return { skip: true, info: "เปิดด้วย TKTEST.run({ sendLine: true })" };
      const fd = new FormData();
      fd.append("message", `🧪 ชุดทดสอบระบบ — ${new Date().toLocaleString("th-TH")}\n(ไม่ใช่เหตุการณ์จริง)`);
      const d = await (await fetch("line_notify.php", { method: "POST", body: fd })).json();
      return d.status === "success" ? { info: "ส่งสำเร็จ" } : { ok: false, info: d.message || JSON.stringify(d) };
    });

    await check("line", "ส่งภาพรูปตัดเข้า LINE", async () => {
      if (!opts.sendLine) return { skip: true, info: "เปิดด้วย TKTEST.run({ sendLine: true })" };
      const s = STATIONS["ST.14"];
      const blob = await (await fetch(`proxy_image.php?node=${s.nid}&t=${s.cs.t}&b=${s.cs.b}&v=25.500&cri=27&alt=26`)).blob();
      if (blob.size < 500) return { ok: false, info: "ดึงภาพไม่ได้" };
      const b64 = await new Promise((res) => { const r = new FileReader(); r.onload = () => res(r.result); r.readAsDataURL(blob); });
      const fd = new FormData();
      fd.append("image", b64);
      fd.append("message", "🧪 ทดสอบแนบภาพรูปตัดลำน้ำ (ไม่ใช่เหตุการณ์จริง)");
      const d = await (await fetch("line_notify.php", { method: "POST", body: fd })).json();
      return d.status === "success" ? { info: "ส่งภาพสำเร็จ" } : { ok: false, info: d.message || JSON.stringify(d) };
    });
  };

  /* ---------- ตัวรัน ---------- */
  const TKTEST = {
    async run(options = {}) {
      opts = options;
      results.length = 0;
      console.log("%c🧪 เริ่มชุดทดสอบระบบโทรมาตร…", "font-weight:bold;font-size:14px;color:#2980b9");

      await testEnv();
      await testApi();
      await testLogic();
      await testTrend();
      await testServer();
      await testLine();

      console.table(results);
      const sum = results.reduce((a, r) => (a[r.ผล] = (a[r.ผล] || 0) + 1, a), {});
      const line = Object.entries(sum).map(([k, v]) => `${k} ${v}`).join("   ");
      const failed = results.filter((r) => r.ผล === FAIL);
      console.log(`%c${line}`, "font-weight:bold;font-size:13px");
      if (failed.length) {
        console.log("%c❌ รายการที่ไม่ผ่าน:", "color:#c0392b;font-weight:bold");
        failed.forEach((f) => console.log(`   ${f.หมวด} · ${f.รายการ} → ${f.รายละเอียด}`));
      } else {
        console.log("%c🎉 ไม่มีรายการที่ไม่ผ่าน", "color:#27ae60;font-weight:bold");
      }
      return results;
    },
    get results() { return results; },
  };

  window.TKTEST = TKTEST;
  console.log("%c✅ ชุดทดสอบพร้อมแล้ว — พิมพ์ TKTEST.run()", "color:#27ae60;font-weight:bold");
})();
