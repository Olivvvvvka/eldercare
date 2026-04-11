// ElderCare API Client v2
// Токен передаётся в теле запроса — работает в любом XAMPP без настроек

const ROOT = (function() {
  const p = window.location.pathname;
  const i = p.indexOf('/frontend/');
  return i >= 0 ? p.slice(0, i) : '';
})();
const API = ROOT + '/backend/api.php';

// Хранилище
const Store = {
  get: k => localStorage.getItem(k),
  set: (k, v) => localStorage.setItem(k, v),
  del: k => localStorage.removeItem(k),
  token:   () => localStorage.getItem('token'),
  role:    () => localStorage.getItem('role'),
  name:    () => localStorage.getItem('name'),
  pid:     () => localStorage.getItem('pid'),     // patient_id (для роли пациент)
  selPid:  () => localStorage.getItem('sel_pid'), // выбранный пациент (для родственника/врача)
  loggedIn:() => !!localStorage.getItem('token'),

  save(data) {
    localStorage.setItem('token', data.token);
    localStorage.setItem('uid',   data.uid);
    localStorage.setItem('role',  data.role);
    localStorage.setItem('name',  data.name || '');
    if (data.pid) {
      localStorage.setItem('pid',     data.pid);
      localStorage.setItem('sel_pid', data.pid);
    }
  },
  clear() {
    ['token','uid','role','name','pid','sel_pid'].forEach(k => localStorage.removeItem(k));
  }
};

// Базовый запрос — токен всегда в body
async function api(params, body) {
  const isGet   = !body;
  const token   = Store.token();
  const qs      = new URLSearchParams(params).toString();
  const url     = API + (qs ? '?' + qs : '');

  let fetchOpts;
  if (isGet) {
    // GET: токен в query string
    const url2 = url + (qs ? '&' : '?') + '_token=' + encodeURIComponent(token || '');
    fetchOpts = { method: 'GET', headers: { 'Content-Type': 'application/json' } };
    const res  = await fetch(url2, fetchOpts);
    return handleResponse(res);
  } else {
    // POST: токен в теле
    const payload = Object.assign({}, body, token ? { _token: token } : {});
    fetchOpts = { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) };
    const res = await fetch(url, fetchOpts);
    return handleResponse(res);
  }
}

async function handleResponse(res) {
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); }
  catch(e) {
    console.error('Сервер вернул не JSON:', text.slice(0, 500));
    throw new Error('Ошибка сервера. Открой F12 → Console для деталей.');
  }
  if (res.status === 401) { Store.clear(); location.href = ROOT + '/frontend/index.html?out=1'; return null; }
  if (!res.ok) throw new Error(data.error || 'Ошибка ' + res.status);
  return data;
}

// Методы API
const Api = {
  login:    (email, password) => api({do:'login'},    {email, password}),
  register: (d)               => api({do:'register'}, d),

  myPatient:      ()     => api({do:'my_patient'}),
  myPatients:     ()     => api({do:'my_patients'}),
  connectPatient: (code) => api({do:'connect_patient'}, {code}),
  patientProfile: (pid)  => api({do:'patient_profile', pid}),

  getRecords:  (pid, days)  => api({do:'get_records',  pid, days: days||30}),
  addRecord:   (pid, d)     => api({do:'add_record',   pid}, d),

  getMedicines: (pid, date) => api({do:'get_medicines', pid, date: date||''}),
  addMedicine:  (pid, d)    => api({do:'add_medicine',  pid}, d),
  markIntake:   (d)         => api({do:'mark_intake'},  d),

  getEvents:    (pid, status) => api({do:'get_events',   pid, ...(status?{status}:{})}),
  resolveEvent: (eid)         => api({do:'resolve_event'}, {event_id: eid}),
  addComment:   (pid, d)      => api({do:'add_comment', pid}, d),

  getStats:    (pid, days)  => api({do:'get_stats',       pid, days:  days||30}),
  getHistory:  (pid, f)     => api({do:'get_history',     pid, ...f}),
  adhWeeks:    (pid, weeks) => api({do:'adherence_weeks', pid, weeks: weeks||8}),
  getProfile:  ()           => api({do:'get_profile'}),
  updateProfile:(d)         => api({do:'update_profile'}, d),
};

// ============================================================
// УТИЛИТЫ UI
// ============================================================

function fmtDate(d) {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('ru-RU', {day:'numeric',month:'long',year:'numeric'});
}
function fmtDT(d) {
  if (!d) return '—';
  return new Date(d).toLocaleString('ru-RU', {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});
}
function fmtTime(s) {
  if (!s) return '—';
  if (typeof s === 'string' && s.length <= 8) return s.slice(0,5);
  return new Date(s).toLocaleTimeString('ru-RU',{hour:'2-digit',minute:'2-digit'});
}

function toast(msg, type='info') {
  const colors = {success:'#2e7d6e', danger:'#d94f3d', warning:'#e6a817', info:'#3730a3'};
  const icons  = {success:'✅', danger:'❌', warning:'⚠️', info:'ℹ️'};
  const el = document.createElement('div');
  el.style.cssText = `position:fixed;top:20px;right:20px;z-index:9999;
    background:${colors[type]};color:#fff;padding:14px 20px;border-radius:10px;
    box-shadow:0 4px 20px rgba(0,0,0,0.25);font-size:1rem;font-weight:600;
    max-width:400px;display:flex;align-items:center;gap:10px;
    animation:slideIn .3s ease`;
  el.innerHTML = `<span>${icons[type]}</span><span>${msg}</span>`;
  if (!document.getElementById('toast-css')) {
    const style = document.createElement('style');
    style.id = 'toast-css';
    style.textContent = '@keyframes slideIn{from{opacity:0;transform:translateX(40px)}to{opacity:1;transform:translateX(0)}}';
    document.head.appendChild(style);
  }
  document.body.appendChild(el);
  setTimeout(() => el.remove(), 4000);
}

function spin(el) {
  el.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;padding:32px;gap:12px;color:#888"><div style="width:24px;height:24px;border:3px solid #ddd;border-top-color:#2e7d6e;border-radius:50%;animation:spin .7s linear infinite"></div><span>Загрузка...</span></div>';
  if (!document.getElementById('spin-css')) {
    const s = document.createElement('style');
    s.id = 'spin-css';
    s.textContent = '@keyframes spin{to{transform:rotate(360deg)}}';
    document.head.appendChild(s);
  }
}

function navbar(active) {
  const role   = Store.role();
  const name   = Store.name();
  const labels = {patient:'Пациент', relative:'Родственник', doctor:'Врач'};
  const links  = {
    patient:  [{href:'patient.html',  label:'📋 Дневник'}, {href:'history.html', label:'📊 История'}],
    relative: [{href:'relative.html', label:'👨‍👩‍👧 Наблюдение'}],
    doctor:   [{href:'doctor.html',   label:'🩺 Дашборд'}],
  };
  const settingsLink = `<a href="${ROOT}/frontend/settings.html" class="${'settings.html'.includes(active)?'active':''}">⚙️ Профиль</a>`;
  const navLinks = (links[role]||[]).map(l =>
    `<a href="${ROOT}/frontend/${l.href}" class="${l.href.includes(active)?'active':''}">${l.label}</a>`
  ).join('');
  return `<nav class="navbar"><div class="container">
    <a href="${ROOT}/frontend/index.html?out=1" class="navbar-brand"><span>💚</span><span>ЗаботаОнлайн</span></a>
    <div class="navbar-links">${navLinks} ${settingsLink}</div>
    <div class="navbar-user">
      <span>${name}</span>
      <span class="role-badge">${labels[role]||role}</span>
      <button class="btn btn-ghost btn-sm" onclick="signOut()">Выйти</button>
    </div>
  </div></nav>`;
}

function signOut() { Store.clear(); location.href = ROOT + '/frontend/index.html?out=1'; }

function requireRole(roles) {
  if (!Store.loggedIn()) { location.href = ROOT + '/frontend/index.html'; return false; }
  if (roles && !roles.includes(Store.role())) { location.href = ROOT + '/frontend/index.html'; return false; }
  return true;
}
