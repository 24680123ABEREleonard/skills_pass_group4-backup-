const DB_NAME = 'jongoHubAttendanceDB';
const DB_VERSION = 11;
const DISPLAY_STUDENT_ID = 'RHIBMS300';

const defaultData = {
  admins: [
    { id: 'admin-1', name: 'Jongo Hub Admin', username: 'LEO-BOT-TECH', password: 'SPECIAL AGENTS123' }
  ],
  interns: [
    { id: 'INT-101', name: 'ABERE LEONARD', department: 'CSE' },
    { id: 'INT-102', name: 'AUSTINE BATEKALAC', department: 'CSE' },
    { id: 'INT-118', name: 'ACHOU JERRY AGBOR', department: 'CSE' },
    { id: 'INT-113', name: 'BAH SOLIDAD', department: 'CSE' },
    { id: 'INT-114', name: 'BEKONG STEPHNI', department: 'CSE' },
    { id: 'INT-103', name: 'BENDE PAULINE', department: 'CSE' },
    { id: 'INT-104', name: 'BETECK CHARLES', department: 'CSE' },
    { id: 'INT-105', name: 'CHO REWARD', department: 'CSE' },
    { id: 'INT-111', name: 'EFERENCHA JOSEPH', department: 'CSE' },
    { id: 'INT-106', name: 'ENDA DORINE', department: 'CWD' },
    { id: 'INT-107', name: 'EWANE BAYARD', department: 'CSE' },
    { id: 'INT-112', name: 'JOEL AMAHA BAYONG', department: 'CSE' },
    { id: 'INT-109', name: 'KILOH BERTIN', department: 'CSE' },
    { id: 'INT-108', name: 'KINGE CARLSON', department: 'CSE' },
    { id: 'INT-110', name: 'MALIWA BLESSING', department: 'CSE' },
    { id: 'INT-115', name: 'EBOTE ESSAKA IRIS WILLIAMS', department: 'CSE' },
    { id: 'INT-116', name: 'AYAMBA RICHMOND EKENYA', department: 'CSE' },
    { id: 'INT-117', name: 'LYONGA MWAMBO EMMANUEL', department: 'CSE' }
  ],
  attendance: {}
};

let dbReadyPromise = null;
const todayISO = new Date().toISOString().split('T')[0];

const authSection = document.getElementById('authSection');
const homeSection = document.getElementById('homeSection');
const dashboardSection = document.getElementById('dashboardSection');
const publicViewSection = document.getElementById('publicViewSection');
const loginForm = document.getElementById('loginForm');
const signupForm = document.getElementById('signupForm');
const adminNameDisplay = document.getElementById('adminNameDisplay');
const logoutBtn = document.getElementById('logoutBtn');
const loginMessage = document.getElementById('loginMessage');
const signupMessage = document.getElementById('signupMessage');
const addInternForm = document.getElementById('addInternForm');
const attendanceForm = document.getElementById('attendanceForm');
const attendanceDateInput = document.getElementById('attendanceDate');
const saveAttendancePdfBtn = document.getElementById('saveAttendancePdfBtn');
const downloadAttendanceCsvBtn = document.getElementById('downloadAttendanceCsvBtn');
const publicDateInput = document.getElementById('publicDate');
const viewAttendanceBtn = document.getElementById('viewAttendanceBtn');
const adminSettingsBtn = document.getElementById('adminSettingsBtn');
const returnFromPublicBtn = document.getElementById('returnFromPublicBtn');
const returnFromSettingsBtn = document.getElementById('returnFromSettingsBtn');
const adminSettingsSection = document.getElementById('adminSettingsSection');
const settingsProfileForm = document.getElementById('settingsProfileForm');
const settingsProfileMessage = document.getElementById('settingsProfileMessage');
const settingsNameInput = document.getElementById('settingsName');
const settingsUsernameInput = document.getElementById('settingsUsername');
const settingsSavedPasswordInput = document.getElementById('settingsSavedPassword');
const settingsCurrentPasswordInput = document.getElementById('settingsCurrentPassword');
const settingsNewPasswordInput = document.getElementById('settingsNewPassword');
const settingsConfirmPasswordInput = document.getElementById('settingsConfirmPassword');
const settingsLightThemeBtn = document.getElementById('settingsLightThemeBtn');
const settingsDarkThemeBtn = document.getElementById('settingsDarkThemeBtn');
const accentColorInput = document.getElementById('accentColorInput');
const internsTableBody = document.getElementById('internsTableBody');
const publicTableBody = document.getElementById('publicTableBody');
const pendingAdminTableBody = document.getElementById('pendingAdminTableBody');
const approvalEmptyState = document.getElementById('approvalEmptyState');
const totalInternsValue = document.getElementById('totalInternsValue');
const presentCountValue = document.getElementById('presentCountValue');
const lateCountValue = document.getElementById('lateCountValue');
const absentCountValue = document.getElementById('absentCountValue');
const statusSummary = document.getElementById('statusSummary');
const themeToggle = document.getElementById('themeToggle');
const brandHomeBtn = document.getElementById('brandHomeBtn');
const authHomeBtn = document.getElementById('authHomeBtn');
const homeViewAttendanceBtn = document.getElementById('homeViewAttendanceBtn');
const homeAdminLoginBtn = document.getElementById('homeAdminLoginBtn');
const publicTotalValue = document.getElementById('publicTotalValue');
const publicPresentValue = document.getElementById('publicPresentValue');
const publicLateValue = document.getElementById('publicLateValue');
const publicAbsentValue = document.getElementById('publicAbsentValue');
let activeView = 'home';

init();

async function init() {
  attendanceDateInput.value = todayISO;
  publicDateInput.value = todayISO;
  bindEvents();
  activeView = 'home';
  await seedDatabase();
  await renderApp();
  await renderDashboard();
  await renderPublicAttendance();
}

function openDatabase() {
  if (!dbReadyPromise) {
    dbReadyPromise = new Promise((resolve, reject) => {
      const request = indexedDB.open(DB_NAME, DB_VERSION);

      request.onupgradeneeded = (event) => {
        const db = event.target.result;

        if (!db.objectStoreNames.contains('admins')) {
          db.createObjectStore('admins', { keyPath: 'id' });
        }
        if (!db.objectStoreNames.contains('pendingAdmins')) {
          db.createObjectStore('pendingAdmins', { keyPath: 'id' });
        }
        if (!db.objectStoreNames.contains('interns')) {
          db.createObjectStore('interns', { keyPath: 'id' });
        }
        if (!db.objectStoreNames.contains('attendance')) {
          db.createObjectStore('attendance', { keyPath: 'date' });
        }

        if (event.oldVersion < 5) {
          const internStore = event.target.transaction.objectStore('interns');
          const sampleNames = new Set(['Aisha Mensah', 'Daniel Boateng', 'Kwame Owusu', 'Evelyn Kwarteng']);
          const interimRosterIds = new Set(Array.from({ length: 14 }, (_, index) => `INT-${String(index + 1).padStart(3, '0')}`));
          const requestedNames = new Set(defaultData.interns.map((intern) => intern.name));
          const cursorRequest = internStore.openCursor();
          cursorRequest.onsuccess = (cursorEvent) => {
            const cursor = cursorEvent.target.result;
            if (cursor) {
              if (sampleNames.has(cursor.value.name) || (interimRosterIds.has(cursor.value.id) && requestedNames.has(cursor.value.name))) {
                cursor.delete();
              }
              cursor.continue();
              return;
            }
            defaultData.interns.forEach((intern) => internStore.put(intern));
          };
        }

        if (event.oldVersion < 6) {
          const internStore = event.target.transaction.objectStore('interns');
          defaultData.interns.forEach((intern) => internStore.put(intern));
        }

        if (event.oldVersion < 7) {
          const internStore = event.target.transaction.objectStore('interns');
          defaultData.interns
            .filter((intern) => ['INT-115', 'INT-116', 'INT-117'].includes(intern.id))
            .forEach((intern) => internStore.put(intern));
        }

        if (event.oldVersion < 8) {
          const internStore = event.target.transaction.objectStore('interns');
          internStore.put(defaultData.interns.find((intern) => intern.id === 'INT-118'));
        }

        if (event.oldVersion < 9) {
          const adminStore = event.target.transaction.objectStore('admins');
          const defaultAdminRequest = adminStore.get('admin-1');
          defaultAdminRequest.onsuccess = () => {
            const admin = defaultAdminRequest.result;
            if (admin?.username === 'admin' && admin.password === 'admin123') {
              adminStore.put({ ...admin, password: 'SPECIAL AGENTS123' });
            }
          };
        }

        if (event.oldVersion < 10) {
          const adminStore = event.target.transaction.objectStore('admins');
          const defaultAdminRequest = adminStore.get('admin-1');
          defaultAdminRequest.onsuccess = () => {
            const admin = defaultAdminRequest.result;
            if (admin?.username === 'admin') {
              adminStore.put({ ...admin, username: 'LEO-BOT-TECH' });
            }
          };
        }

        if (event.oldVersion < 11) {
          const adminStore = event.target.transaction.objectStore('admins');
          const defaultAdminRequest = adminStore.get('admin-1');
          defaultAdminRequest.onsuccess = () => {
            const admin = defaultAdminRequest.result;
            if (!admin) return;

            const updatedAdmin = {
              ...admin,
              ...(admin.username === 'admin' ? { username: 'LEO-BOT-TECH' } : {}),
              ...(admin.password === 'admin123' ? { password: 'SPECIAL AGENTS123' } : {})
            };
            if (updatedAdmin.username !== admin.username || updatedAdmin.password !== admin.password) {
              adminStore.put(updatedAdmin);
            }
          };
        }
      };

      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
  }
  return dbReadyPromise;
}

async function seedDatabase() {
  const db = await openDatabase();
  const tx = db.transaction(['admins', 'interns'], 'readwrite');
  const adminStore = tx.objectStore('admins');
  const internStore = tx.objectStore('interns');
  const adminCount = await requestCount(adminStore);
  const internCount = await requestCount(internStore);

  if (adminCount === 0) defaultData.admins.forEach((admin) => adminStore.put(admin));
  if (internCount === 0) defaultData.interns.forEach((intern) => internStore.put(intern));
  await transactionComplete(tx);
}

function requestCount(store) {
  return new Promise((resolve, reject) => {
    const request = store.count();
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

function requestAll(store) {
  return new Promise((resolve, reject) => {
    const request = store.getAll();
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

function transactionComplete(transaction) {
  return new Promise((resolve, reject) => {
    transaction.oncomplete = () => resolve();
    transaction.onerror = () => reject(transaction.error);
    transaction.onabort = () => reject(transaction.error);
  });
}

function bindEvents() {
  loginForm.addEventListener('submit', handleLogin);
  signupForm.addEventListener('submit', handleSignup);
  logoutBtn.addEventListener('click', handleLogout);
  addInternForm.addEventListener('submit', handleAddIntern);
  attendanceForm.addEventListener('submit', handleAttendanceUpdate);
  saveAttendancePdfBtn.addEventListener('click', saveAttendancePdf);
  if (downloadAttendanceCsvBtn) downloadAttendanceCsvBtn.addEventListener('click', downloadAttendanceCsv);
  internsTableBody.addEventListener('change', refreshAttendanceStudentLists);
  internsTableBody.addEventListener('click', handleInternAction);
  internsTableBody.addEventListener('keydown', handleInternEditKeydown);              
  pendingAdminTableBody.addEventListener('click', handleAdminApprovalAction);
  attendanceDateInput.addEventListener('change', renderDashboard);
  publicDateInput.addEventListener('change', renderPublicAttendance);
  themeToggle.addEventListener('click', toggleTheme);
  brandHomeBtn.addEventListener('click', returnHome);
  authHomeBtn.addEventListener('click', returnHome);
  homeViewAttendanceBtn.addEventListener('click', openPublicView);
  homeAdminLoginBtn.addEventListener('click', openAdminLogin);
  adminNameDisplay.addEventListener('click', handleGuestAccess);
  viewAttendanceBtn.addEventListener('click', openPublicView);
  returnFromPublicBtn.addEventListener('click', returnFromPublicView);
  adminSettingsBtn.addEventListener('click', openAdminSettings);
  returnFromSettingsBtn.addEventListener('click', returnFromAdminSettings);
  settingsProfileForm.addEventListener('submit', handleSettingsProfileUpdate);
  settingsLightThemeBtn.addEventListener('click', () => applyTheme('light'));
  settingsDarkThemeBtn.addEventListener('click', () => applyTheme('dark'));
  accentColorInput.addEventListener('input', () => applyAccentColor(accentColorInput.value));

  document.querySelectorAll('.toggle-password').forEach((button) => {
    button.addEventListener('click', () => togglePasswordVisibility(button));
  });

  const savedTheme = localStorage.getItem('jongoHubTheme') || 'light';
  applyTheme(savedTheme);
  const savedAccent = localStorage.getItem('jongoHubAccentColor') || '#1d4ed8';
  accentColorInput.value = savedAccent;
  applyAccentColor(savedAccent);
}             

async function getAllAdmins() {
  const db = await openDatabase();
  const tx = db.transaction('admins', 'readonly');
  return requestAll(tx.objectStore('admins'));
}

async function getAllPendingAdmins() {
  const db = await openDatabase();
  const tx = db.transaction('pendingAdmins', 'readonly');
  return requestAll(tx.objectStore('pendingAdmins'));
}

async function savePendingAdmin(request) {
  const db = await openDatabase();
  const tx = db.transaction('pendingAdmins', 'readwrite');
  tx.objectStore('pendingAdmins').put(request);
  await transactionComplete(tx);
}

async function deletePendingAdmin(requestId) {
  const db = await openDatabase();
  const tx = db.transaction('pendingAdmins', 'readwrite');
  tx.objectStore('pendingAdmins').delete(requestId);
  await transactionComplete(tx);
}

async function getAllInterns() {
  const db = await openDatabase();
  const tx = db.transaction('interns', 'readonly');
  const interns = await requestAll(tx.objectStore('interns'));
  return interns.sort((first, second) => first.name.localeCompare(second.name));
}

async function getAttendanceRecordForDate(date) {
  const db = await openDatabase();
  const tx = db.transaction('attendance', 'readonly');
  const request = tx.objectStore('attendance').get(date);
  return new Promise((resolve, reject) => {
    request.onsuccess = () => resolve({
      records: request.result?.records || {},
      reasons: request.result?.reasons || {}
    });
    request.onerror = () => reject(request.error);
  });
}

async function getCurrentAdmin() {
  const adminId = sessionStorage.getItem('jongoHubCurrentAdminId');
  if (!adminId) return null;
  const db = await openDatabase();
  const tx = db.transaction('admins', 'readonly');
  const request = tx.objectStore('admins').get(adminId);
  return new Promise((resolve, reject) => {
    request.onsuccess = () => resolve(request.result || null);
    request.onerror = () => reject(request.error);
  });
}

async function saveAdmin(admin) {
  const db = await openDatabase();
  const tx = db.transaction('admins', 'readwrite');
  tx.objectStore('admins').put(admin);
  await transactionComplete(tx);
}

async function saveIntern(intern) {
  const db = await openDatabase();
  const tx = db.transaction('interns', 'readwrite');
  tx.objectStore('interns').put(intern);
  await transactionComplete(tx);
}

async function deleteIntern(internId) {
  const db = await openDatabase();
  const tx = db.transaction('interns', 'readwrite');
  tx.objectStore('interns').delete(internId);
  await transactionComplete(tx);
}

async function saveAttendanceForDate(date, records, reasons) {
  const db = await openDatabase();
  const tx = db.transaction('attendance', 'readwrite');
  tx.objectStore('attendance').put({ date, records, reasons });
  await transactionComplete(tx);
}

async function renderApp() {
  const loggedIn = isAdminLoggedIn();
  const viewingHome = activeView === 'home';
  const viewingPublic = activeView === 'public';
  const viewingSettings = activeView === 'settings';
  homeSection.classList.toggle('hidden', !viewingHome);
  authSection.classList.toggle('hidden', loggedIn || viewingHome || viewingPublic || viewingSettings);
  dashboardSection.classList.toggle('hidden', !loggedIn || viewingHome || viewingPublic || viewingSettings);
  adminSettingsSection.classList.toggle('hidden', !loggedIn || !viewingSettings);
  publicViewSection.classList.toggle('hidden', !viewingPublic);
  logoutBtn.classList.toggle('hidden', !loggedIn);
  viewAttendanceBtn.classList.toggle('hidden', viewingHome || viewingPublic || viewingSettings);
  adminSettingsBtn.classList.toggle('hidden', !loggedIn || viewingHome || viewingPublic || viewingSettings);
  homeAdminLoginBtn.textContent = loggedIn ? 'Admin Dashboard' : 'Admin Login';
  document.body.classList.toggle('auth-screen', !loggedIn && activeView === 'auth');
  returnFromPublicBtn.textContent = loggedIn ? 'Back to Admin Dashboard' : 'Back to Login';

  if (loggedIn) {
    const currentAdmin = await getCurrentAdmin();
    adminNameDisplay.textContent = currentAdmin ? `Logged in as ${currentAdmin.name}` : 'Admin';
    adminNameDisplay.setAttribute('aria-label', 'Admin account');
    if (currentAdmin) {
      settingsNameInput.value = currentAdmin.name;
      settingsUsernameInput.value = currentAdmin.username;
      settingsSavedPasswordInput.value = currentAdmin.password;
    }
  } else {
    adminNameDisplay.textContent = 'Guest';
    adminNameDisplay.setAttribute('aria-label', 'Guest access');
    settingsSavedPasswordInput.value = '';
  }
}

async function renderDashboard() {
  if (!isAdminLoggedIn()) {
    return;
  }

  const selectedDate = attendanceDateInput.value || todayISO;
  const [interns, dayAttendance, pendingAdmins, attendanceRecords] = await Promise.all([
    getAllInterns(),
    getAttendanceRecordForDate(selectedDate),
    getAllPendingAdmins(),
    getAllAttendance()
  ]);
  const { records: dayRecords, reasons: dayReasons } = dayAttendance;
  const attendanceMap = Object.fromEntries(attendanceRecords.map((entry) => [entry.date, entry.records || {}]));
  const total = interns.length;
  saveAttendancePdfBtn.disabled = !Object.hasOwn(attendanceMap, selectedDate);
  if (downloadAttendanceCsvBtn) downloadAttendanceCsvBtn.disabled = !Object.hasOwn(attendanceMap, selectedDate);

  totalInternsValue.textContent = total;
  const activeStatuses = interns.map((intern) => dayRecords[intern.id] || 'Not marked');
  presentCountValue.textContent = activeStatuses.filter((value) => value === 'Present').length;
  lateCountValue.textContent = activeStatuses.filter((value) => value === 'Late').length;
  absentCountValue.textContent = activeStatuses.filter((value) => value === 'Absent').length;
  renderPendingAdminApprovals(pendingAdmins);

  statusSummary.textContent = `Attendance for ${selectedDate}: ${presentCountValue.textContent} present, ${lateCountValue.textContent} late, ${absentCountValue.textContent} absent.`;

  internsTableBody.innerHTML = '';

  interns.forEach((intern) => {
    const row = document.createElement('tr');
    const statusValue = dayRecords[intern.id] || 'Not marked';
    const serialCell = document.createElement('td');
    const nameCell = document.createElement('td');
    const departmentCell = document.createElement('td');
    const idCell = document.createElement('td');
    const statusCell = document.createElement('td');
    const reasonCell = document.createElement('td');
    const currentCell = document.createElement('td');
    const actionsCell = document.createElement('td');
    const statusSelect = document.createElement('select');
    const reasonInput = document.createElement('textarea');

    serialCell.textContent = String(internsTableBody.children.length + 1);
    nameCell.textContent = intern.name;
    departmentCell.textContent = intern.department;
    idCell.textContent = DISPLAY_STUDENT_ID;
    statusSelect.className = 'attendance-status-select';
    statusSelect.dataset.internId = intern.id;
    ['Not marked', 'Present', 'Late', 'Absent', 'Leave'].forEach((status) => {
      const option = document.createElement('option');
      option.value = status;
      option.textContent = status;
      option.selected = statusValue === status;
      statusSelect.appendChild(option);
    });
    statusCell.appendChild(statusSelect);
    reasonInput.className = 'absence-reason-input';
    reasonInput.rows = 2;
    reasonInput.maxLength = 200;
    reasonInput.placeholder = 'Brief reason for absence (optional)';
    reasonInput.setAttribute('aria-label', `Absence reason for ${intern.name}`);
    reasonInput.dataset.internId = intern.id;
    reasonInput.value = dayReasons[intern.id] || '';
    reasonCell.appendChild(reasonInput);
    currentCell.appendChild(createStatusBadge(statusValue === 'Not marked' ? 'Pending' : statusValue));
    actionsCell.className = 'intern-actions';
    renderInternActionButtons(actionsCell, intern.id);
    row.append(serialCell, nameCell, departmentCell, idCell, statusCell, reasonCell, currentCell, actionsCell);
    internsTableBody.appendChild(row);
  });

  refreshAttendanceStudentLists();
}

function refreshAttendanceStudentLists() {
  const presentNames = [];
  const absentNames = [];

  internsTableBody.querySelectorAll('tr').forEach((row) => {
    const nameInput = row.cells[1].querySelector('input');
    const name = nameInput ? nameInput.value : row.cells[1].textContent;
    const status = row.querySelector('select').value;
    const reasonInput = row.querySelector('textarea[data-intern-id]');
    const serialNumber = row.cells[0].textContent;
    reasonInput.hidden = status !== 'Absent';
    reasonInput.disabled = status !== 'Absent';
    if (status === 'Present') presentNames.push(`${serialNumber}. ${name}`);
    if (status === 'Absent') absentNames.push(`${serialNumber}. ${name}`);
  });

  renderStudentNames('presentStudentsList', presentNames, 'No students marked present.');
  renderStudentNames('absentStudentsList', absentNames, 'No students marked absent.');
}

function renderStudentNames(listId, names, emptyMessage) {
  const list = document.getElementById(listId);
  list.replaceChildren();

  const entries = names.length ? names : [emptyMessage];
  entries.forEach((name) => {
    const item = document.createElement('li');
    item.textContent = name;
    if (!names.length) item.setAttribute('aria-label', emptyMessage);
    list.appendChild(item);
  });
}

async function renderPublicAttendance() {
  const selectedDate = publicDateInput.value || todayISO;
  const [interns, attendanceRecords] = await Promise.all([
    getAllInterns(),
    getAllAttendance()
  ]);
  const attendanceMap = Object.fromEntries(attendanceRecords.map((entry) => [entry.date, entry.records || {}]));
  const dayRecords = attendanceMap[selectedDate] || {};

  publicTotalValue.textContent = interns.length;
  const activeStatuses = interns.map((intern) => dayRecords[intern.id] || 'Not marked');
  publicPresentValue.textContent = activeStatuses.filter((status) => status === 'Present').length;
  publicLateValue.textContent = activeStatuses.filter((status) => status === 'Late').length;
  publicAbsentValue.textContent = activeStatuses.filter((status) => status === 'Absent').length;

  publicTableBody.innerHTML = '';

  interns.forEach((intern, index) => {
    const row = document.createElement('tr');
    const status = dayRecords[intern.id] || 'Not marked';
    const serialCell = document.createElement('td');
    const nameCell = document.createElement('td');
    const departmentCell = document.createElement('td');
    const idCell = document.createElement('td');
    const statusCell = document.createElement('td');

    serialCell.textContent = String(index + 1);
    nameCell.textContent = intern.name;
    departmentCell.textContent = intern.department;
    idCell.textContent = DISPLAY_STUDENT_ID;
    statusCell.appendChild(createStatusBadge(status === 'Not marked' ? 'Pending' : status));
    row.append(serialCell, nameCell, departmentCell, idCell, statusCell);

    publicTableBody.appendChild(row);
  });
}

async function handleLogin(event) {
  event.preventDefault();
  const username = document.getElementById('loginUsername').value.trim();
  const password = document.getElementById('loginPassword').value;

  const admins = await getAllAdmins();
  const admin = admins.find((user) => user.username.toLowerCase() === username.toLowerCase() && user.password === password);
  if (!admin) {
    showMessage(loginMessage, 'Invalid username or password.', true);
    return;
  }

  sessionStorage.setItem('jongoHubCurrentAdminId', admin.id);
  activeView = 'admin';
  showMessage(loginMessage, 'Login successful.', false);
  await renderApp();
  await renderDashboard();
  loginForm.reset();
}

async function handleSignup(event) {
  event.preventDefault();
  const name = document.getElementById('signupName').value.trim();
  const username = document.getElementById('signupUsername').value.trim();
  const password = document.getElementById('signupPassword').value;
  const confirmPassword = document.getElementById('signupConfirmPassword').value;

  if (!name || !username || !password) {
    showMessage(signupMessage, 'Please complete all fields.', true);
    return;
  }

  if (password.length < 6) {
    showMessage(signupMessage, 'Password must be at least 6 characters.', true);
    return;
  }

  if (password !== confirmPassword) {
    showMessage(signupMessage, 'Passwords do not match.', true);
    return;
  }

  const [admins, pendingAdmins] = await Promise.all([getAllAdmins(), getAllPendingAdmins()]);
  const usernameExists = [...admins, ...pendingAdmins].some((user) => user.username.toLowerCase() === username.toLowerCase());
  if (usernameExists) {
    showMessage(signupMessage, 'This username is already taken.', true);
    return;
  }

  await savePendingAdmin({
    id: `request-${Date.now()}-${Math.random().toString(36).slice(2)}`,
    name,
    username,
    password,
    requestedAt: new Date().toISOString()
  });
  showMessage(signupMessage, 'Signup request submitted. An admin must approve it before you can log in.', false);
  signupForm.reset();
}

function renderPendingAdminApprovals(requests) {
  pendingAdminTableBody.innerHTML = '';
  approvalEmptyState.classList.toggle('hidden', requests.length > 0);

  requests.forEach((request) => {
    const row = document.createElement('tr');
    const nameCell = document.createElement('td');
    const usernameCell = document.createElement('td');
    const dateCell = document.createElement('td');
    const actionsCell = document.createElement('td');
    const approveButton = document.createElement('button');
    const rejectButton = document.createElement('button');

    nameCell.textContent = request.name;
    usernameCell.textContent = request.username;
    dateCell.textContent = new Date(request.requestedAt).toLocaleDateString();
    approveButton.type = 'button';
    approveButton.className = 'primary-btn';
    approveButton.dataset.action = 'approve';
    approveButton.dataset.requestId = request.id;
    approveButton.textContent = 'Approve';
    rejectButton.type = 'button';
    rejectButton.className = 'danger-btn';
    rejectButton.dataset.action = 'reject';
    rejectButton.dataset.requestId = request.id;
    rejectButton.textContent = 'Reject';
    actionsCell.append(approveButton, rejectButton);
    row.append(nameCell, usernameCell, dateCell, actionsCell);
    pendingAdminTableBody.appendChild(row);
  });
}

async function handleAdminApprovalAction(event) {
  const button = event.target.closest('button[data-action]');
  if (!button || !isAdminLoggedIn()) return;

  const requestId = button.dataset.requestId;
  const requests = await getAllPendingAdmins();
  const signupRequest = requests.find((request) => request.id === requestId);
  if (!signupRequest) return;

  if (button.dataset.action === 'approve') {
    const admins = await getAllAdmins();
    if (admins.some((admin) => admin.username.toLowerCase() === signupRequest.username.toLowerCase())) {
      showMessage(approvalEmptyState, 'That username is already assigned to an admin. Reject this request.', true);
      approvalEmptyState.classList.remove('hidden');
      return;
    }
    await saveAdmin({
      id: `admin-${signupRequest.id}`,
      name: signupRequest.name,
      username: signupRequest.username,
      password: signupRequest.password
    });
  }
  await deletePendingAdmin(requestId);
  await renderDashboard();
}

async function handleLogout() {
  sessionStorage.removeItem('jongoHubCurrentAdminId');
  activeView = 'auth';
  settingsSavedPasswordInput.value = '';
  settingsCurrentPasswordInput.value = '';
  settingsNewPasswordInput.value = '';
  settingsConfirmPasswordInput.value = '';
  await renderApp();
}

async function handleAddIntern(event) {
  event.preventDefault();
  const name = document.getElementById('internName').value.trim();
  const department = document.getElementById('internDepartment').value.trim();

  if (!name || !department) {
    alert('Please enter both intern name and department.');
    return;
  }

  const [interns, attendanceHistory] = await Promise.all([getAllInterns(), getAllAttendance()]);
  const largestNumber = interns.reduce((largest, intern) => {
    const match = intern.id.match(/^INT-(\d+)$/);
    return match ? Math.max(largest, Number(match[1])) : largest;
  }, 0);
  const nextNumber = attendanceHistory.reduce((largest, entry) => (
    Object.keys(entry.records || {}).reduce((recordLargest, id) => {
      const match = id.match(/^INT-(\d+)$/);
      return match ? Math.max(recordLargest, Number(match[1])) : recordLargest;
    }, largest)
  ), largestNumber) + 1;
  await saveIntern({ id: `INT-${String(nextNumber).padStart(3, '0')}`, name, department });
  addInternForm.reset();
  await renderDashboard();
  await renderPublicAttendance();
  alert('Intern added successfully.');
}

async function handleInternAction(event) {
  const button = event.target.closest('button[data-action]');
  if (!button || !isAdminLoggedIn()) return;

  const row = button.closest('tr');
  const internId = button.dataset.internId;
  if (!row || !internId) return;

  if (button.dataset.action === 'delete') {
    const internName = row.cells[1].textContent;
    if (!confirm(`Remove ${internName} from the active intern roster? Past attendance records will be retained.`)) return;
    await deleteIntern(internId);
    await renderDashboard();
    await renderPublicAttendance();
    alert('Intern removed successfully.');
    return;
  }

  if (button.dataset.action === 'edit') {
    const name = row.cells[1].textContent;
    const department = row.cells[2].textContent;
    row.dataset.originalName = name;
    row.dataset.originalDepartment = department;
    row.cells[1].replaceChildren(createInternEditInput('name', name, 'Intern name'));
    row.cells[2].replaceChildren(createInternEditInput('department', department, 'Department'));
    row.cells[7].replaceChildren(
      createInternActionButton('save', internId, 'Save', 'primary-btn'),
      createInternActionButton('cancel', internId, 'Cancel', 'secondary-btn')
    );
    row.cells[1].querySelector('input').focus();
    return;
  }

  if (button.dataset.action === 'cancel') {
    row.cells[1].textContent = row.dataset.originalName;
    row.cells[2].textContent = row.dataset.originalDepartment;
    renderInternActionButtons(row.cells[7], internId);
    delete row.dataset.originalName;
    delete row.dataset.originalDepartment;
    refreshAttendanceStudentLists();
    return;
  }

  if (button.dataset.action === 'save') {
    const name = row.cells[1].querySelector('input').value.trim();
    const department = row.cells[2].querySelector('input').value.trim();
    if (!name || !department) {
      alert('Please enter both intern name and department.');
      return;
    }

    await saveIntern({ id: internId, name, department });
    row.cells[1].textContent = name;
    row.cells[2].textContent = department;
    renderInternActionButtons(row.cells[7], internId);
    delete row.dataset.originalName;
    delete row.dataset.originalDepartment;
    refreshAttendanceStudentLists();
    await renderPublicAttendance();
  }
}

function handleInternEditKeydown(event) {
  if (!event.target.matches('input[data-field]')) return;
  if (event.key === 'Enter') {
    event.preventDefault();
    event.target.closest('tr').querySelector('button[data-action="save"]').click();
  } else if (event.key === 'Escape') {
    event.preventDefault();
    event.target.closest('tr').querySelector('button[data-action="cancel"]').click();
  }
}

function createInternEditInput(field, value, label) {
  const input = document.createElement('input');
  input.type = 'text';
  input.value = value;
  input.required = true;
  input.maxLength = 100;
  input.dataset.field = field;
  input.setAttribute('aria-label', label);
  return input;
}

function createInternActionButton(action, internId, label, className) {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = className;
  button.dataset.action = action;
  button.dataset.internId = internId;
  button.textContent = label;
  return button;
}

function renderInternActionButtons(cell, internId) {
  cell.replaceChildren(
    createInternActionButton('edit', internId, 'Edit', 'secondary-btn'),
    createInternActionButton('delete', internId, 'Delete', 'danger-btn')
  );
}

async function handleAttendanceUpdate(event) {
  event.preventDefault();
  const selectedDate = attendanceDateInput.value || todayISO;

  if (!selectedDate) {
    alert('Please select a date.');
    return;
  }

  const rows = internsTableBody.querySelectorAll('tr');
  const existingAttendance = await getAttendanceRecordForDate(selectedDate);
  const attendanceMap = { ...existingAttendance.records };
  const reasonMap = { ...existingAttendance.reasons };

  rows.forEach((row) => {
    const select = row.querySelector('select');
    const internId = select.dataset.internId;
    const value = select.value;
    const reason = row.querySelector('textarea[data-intern-id]').value.trim();

    delete attendanceMap[internId];
    delete reasonMap[internId];
    if (value && value !== 'Not marked') {
      attendanceMap[internId] = value;
      if (value === 'Absent' && reason) reasonMap[internId] = reason;
    }
  });

  await saveAttendanceForDate(selectedDate, attendanceMap, reasonMap);
  await renderDashboard();
  await renderPublicAttendance();
  alert('Daily attendance updated successfully.');
}

async function saveAttendancePdf() {
  const date = attendanceDateInput.value || todayISO;
  const fileName = `jongo-hub-attendance-${date}.pdf`;
  let filePickerPromise = null;
  if (typeof window.showSaveFilePicker === 'function') {
    try {
      filePickerPromise = window.showSaveFilePicker({
        suggestedName: fileName,
        types: [{ description: 'PDF document', accept: { 'application/pdf': ['.pdf'] } }]
      }).then(
        (fileHandle) => ({ fileHandle }),
        (error) => ({ error })
      );
    } catch (error) {
      filePickerPromise = Promise.resolve({ error });
    }
  }

  const attendanceRecords = await getAllAttendance();
  const savedRecord = attendanceRecords.find((record) => record.date === date);
  if (!savedRecord) return;

  const [interns, attendance] = await Promise.all([getAllInterns(), getAttendanceRecordForDate(date)]);
  const statuses = interns.map((intern) => ({
    intern,
    status: attendance.records[intern.id] || 'Not marked',
    reason: attendance.reasons[intern.id] || ''
  }));
  const countFor = (status) => statuses.filter((entry) => entry.status === status).length;
  const displayDate = new Date(`${date}T00:00:00`).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  });

  const pdfBlob = createAttendancePdf(displayDate, interns.length, statuses, countFor);
  if (filePickerPromise) {
    const { fileHandle, error } = await filePickerPromise;
    if (error?.name === 'AbortError') return;
    if (fileHandle) {
      try {
      const writable = await fileHandle.createWritable();
      await writable.write(pdfBlob);
      await writable.close();
      statusSummary.textContent = `Attendance PDF saved as ${fileHandle.name}.`;
      return;
      } catch (writeError) {
        alert(`Could not save the PDF: ${writeError.message}`);
        return;
      }
    }
  }

  const downloadUrl = URL.createObjectURL(pdfBlob);
  const downloadLink = document.createElement('a');
  downloadLink.href = downloadUrl;
  downloadLink.download = fileName;
  downloadLink.click();
  setTimeout(() => URL.revokeObjectURL(downloadUrl), 1000);
  statusSummary.textContent = `Attendance PDF downloaded as ${fileName}. Check your browser's Downloads folder.`;
}

async function downloadAttendanceCsv() {
  const date = attendanceDateInput.value || todayISO;
  const fileName = `jongo-hub-attendance-${date}.csv`;
  let filePickerPromise = null;
  if (typeof window.showSaveFilePicker === 'function') {
    try {
      filePickerPromise = window.showSaveFilePicker({
        suggestedName: fileName,
        types: [{ description: 'CSV file', accept: { 'text/csv': ['.csv'] } }]
      }).then((fileHandle) => ({ fileHandle }), (error) => ({ error }));
    } catch (error) {
      filePickerPromise = Promise.resolve({ error });
    }
  }

  const attendanceRecords = await getAllAttendance();
  const savedRecord = attendanceRecords.find((record) => record.date === date);
  if (!savedRecord) return;

  const [interns, attendance] = await Promise.all([getAllInterns(), getAttendanceRecordForDate(date)]);
  const header = ['No', 'Name', 'Department', 'Intern ID', 'Status', 'Absence Reason'];
  const rows = interns.map((intern, index) => {
    const status = attendance.records[intern.id] || 'Not marked';
    const reason = status === 'Absent' ? attendance.reasons[intern.id] || '' : '';
    return [index + 1, intern.name, intern.department, intern.id, status, reason];
  });

  const csvContent = [header, ...rows]
    .map((row) => row
      .map((cell) => `"${String(cell).replace(/"/g, '""')}"`)
      .join(','))
    .join('\r\n');

  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });

  if (filePickerPromise) {
    const { fileHandle, error } = await filePickerPromise;
    if (error?.name === 'AbortError') return;
    if (fileHandle) {
      try {
        const writable = await fileHandle.createWritable();
        await writable.write(blob);
        await writable.close();
        statusSummary.textContent = `Attendance CSV saved as ${fileHandle.name}.`;
        return;
      } catch (writeError) {
        alert(`Could not save the CSV: ${writeError.message}`);
        return;
      }
    }
  }

  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = fileName;
  a.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
  statusSummary.textContent = `Attendance CSV downloaded as ${fileName}. Check your browser's Downloads folder.`;
}

function createAttendancePdf(displayDate, totalStudents, statuses, countFor) {
  const pageWidth = 595.28;
  const pageHeight = 841.89;
  const margin = 40;
  const headerHeight = 26;
  const rowHeight = 40;
  const columnWidths = [26, 130, 60, 75, 52, 172];
  const headings = ['No.', 'Student Name', 'Department', 'Student ID', 'Status', 'Absence Reason'];
  const pages = [];
  let index = 0;

  while (index < statuses.length || pages.length === 0) {
    const isFirstPage = pages.length === 0;
    const tableTop = isFirstPage ? 130 : 86;
    const capacity = Math.max(1, Math.floor((pageHeight - margin - tableTop - headerHeight) / rowHeight));
    const startIndex = index;
    const pageRows = statuses.slice(index, index + capacity);
    index += pageRows.length;
    pages.push({ tableTop, pageRows, startIndex });
  }

  const escapePdfText = (value) => String(value)
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^\x20-\x7e]/g, '?')
    .replace(/\\/g, '\\\\')
    .replace(/\(/g, '\\(')
    .replace(/\)/g, '\\)');

  const wrapReason = (reason) => {
    const lines = [];
    let line = '';
    const words = reason.trim().split(/\s+/).flatMap((word) => {
      const chunks = [];
      for (let index = 0; index < word.length; index += 46) {
        chunks.push(word.slice(index, index + 46));
      }
      return chunks;
    });
    words.forEach((word) => {
      const nextLine = line ? `${line} ${word}` : word;
      if (nextLine.length > 46 && line) {
        lines.push(line);
        line = word;
      } else {
        line = nextLine;
      }
    });
    if (line) lines.push(line);
    if (lines.length > 3) {
      lines.length = 3;
      lines[2] = `${lines[2].slice(0, 43)}...`;
    }
    return lines;
  };

  const objects = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'
  ];
  const pageObjectIds = [];

  pages.forEach(({ tableTop, pageRows, startIndex }, pageIndex) => {
    const commands = [];
    const addText = (text, x, baselineFromTop, size, bold = false, color = '0.12 0.16 0.22') => {
      commands.push(`BT /${bold ? 'F2' : 'F1'} ${size} Tf ${color} rg 1 0 0 1 ${x} ${pageHeight - baselineFromTop} Tm (${escapePdfText(text)}) Tj ET`);
    };
    const drawLine = (x1, y1, x2, y2) => {
      commands.push(`0.65 G 0.5 w ${x1} ${pageHeight - y1} m ${x2} ${pageHeight - y2} l S`);
    };

    if (pageIndex === 0) {
      addText('JONGO HUB', margin, 48, 20, true);
      addText('Daily Attendance Report', margin, 70, 14, true);
      addText(`Date: ${displayDate}`, margin, 91, 10);
      addText(`Total students: ${totalStudents}`, margin, 108, 10);
      addText(`Present: ${countFor('Present')}  |  Late: ${countFor('Late')}  |  Absent: ${countFor('Absent')}  |  Leave: ${countFor('Leave')}`, margin, 122, 9);
    } else {
      addText(`JONGO HUB  |  Daily Attendance Report  |  ${displayDate}`, margin, 48, 11, true);
    }

    const totalTableWidth = columnWidths.reduce((sum, width) => sum + width, 0);
    const headerBottom = pageHeight - tableTop - headerHeight;
    commands.push(`0.11 0.25 0.55 rg ${margin} ${headerBottom} ${totalTableWidth} ${headerHeight} re f`);
    let x = margin;
    headings.forEach((heading, headingIndex) => {
      addText(heading, x + 5, tableTop + 17, 9, true, '1 1 1');
      x += columnWidths[headingIndex];
    });

    pageRows.forEach(({ intern, status, reason }, rowIndex) => {
      const rowTop = tableTop + headerHeight + rowIndex * rowHeight;
      const rowBottom = rowTop + rowHeight;
      drawLine(margin, rowTop, margin + totalTableWidth, rowTop);
      drawLine(margin, rowBottom, margin + totalTableWidth, rowBottom);
      let cellX = margin;
      const values = [String(startIndex + rowIndex + 1), intern.name, intern.department, DISPLAY_STUDENT_ID, status, reason];
      values.forEach((value, valueIndex) => {
        if (valueIndex === 5 && value) {
          wrapReason(value).forEach((line, lineIndex) => {
            addText(line, cellX + 4, rowTop + 11 + lineIndex * 10, 7);
          });
        } else {
          addText(value, cellX + 4, rowTop + 22, 7);
        }
        cellX += columnWidths[valueIndex];
        drawLine(cellX, rowTop, cellX, rowBottom);
      });
      if (rowIndex === 0) drawLine(margin, tableTop, margin + totalTableWidth, tableTop);
    });
    drawLine(margin, tableTop, margin, tableTop + headerHeight + pageRows.length * rowHeight);
    drawLine(margin + totalTableWidth, tableTop, margin + totalTableWidth, tableTop + headerHeight + pageRows.length * rowHeight);
    if (!pageRows.length) drawLine(margin, tableTop + headerHeight, margin + totalTableWidth, tableTop + headerHeight);
    drawLine(margin, pageHeight - 38, pageWidth - margin, pageHeight - 38);
    addText("LEO-BOT'S TECH COMPANY PRODUCTS", (pageWidth - 210) / 2, pageHeight - 22, 9, true, '0.35 0.39 0.45');

    const content = commands.join('\n');
    const pageObjectId = objects.length + 1;
    const contentObjectId = pageObjectId + 1;
    pageObjectIds.push(pageObjectId);
    objects.push(`<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${pageWidth} ${pageHeight}] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ${contentObjectId} 0 R >>`);
    objects.push(`<< /Length ${content.length} >>\nstream\n${content}\nendstream`);
  });

  objects[1] = `<< /Type /Pages /Kids [${pageObjectIds.map((id) => `${id} 0 R`).join(' ')}] /Count ${pageObjectIds.length} >>`;
  let pdf = '%PDF-1.4\n';
  const offsets = [0];
  objects.forEach((object, objectIndex) => {
    offsets.push(pdf.length);
    pdf += `${objectIndex + 1} 0 obj\n${object}\nendobj\n`;
  });
  const xrefOffset = pdf.length;
  pdf += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`;
  offsets.slice(1).forEach((offset) => {
    pdf += `${String(offset).padStart(10, '0')} 00000 n \n`;
  });
  pdf += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xrefOffset}\n%%EOF`;
  return new Blob([pdf], { type: 'application/pdf' });
}

async function getAllAttendance() {
  const db = await openDatabase();
  const tx = db.transaction('attendance', 'readonly');
  return requestAll(tx.objectStore('attendance'));
}

function isAdminLoggedIn() {
  return Boolean(sessionStorage.getItem('jongoHubCurrentAdminId'));
}

function createStatusBadge(status) {
  const badge = document.createElement('span');
  badge.className = `status-badge ${status === 'Present' ? 'status-present' : status === 'Late' ? 'status-late' : status === 'Absent' || status === 'Pending' ? 'status-absent' : 'status-leave'}`;
  badge.textContent = status;
  return badge;
}

function togglePasswordVisibility(button) {
  const targetId = button.getAttribute('data-target');
  const input = document.getElementById(targetId);

  if (!input) return;

  const isPassword = input.type === 'password';
  input.type = isPassword ? 'text' : 'password';
  button.textContent = isPassword ? 'Hide' : 'Show';
}

function handleGuestAccess() {
  if (!isAdminLoggedIn()) openPublicView();
}

async function openPublicView() {
  activeView = 'public';
  await renderApp();
  await renderPublicAttendance();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function returnFromPublicView() {
  activeView = isAdminLoggedIn() ? 'admin' : 'home';
  await renderApp();
  if (activeView === 'admin') await renderDashboard();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function openAdminSettings() {
  if (!isAdminLoggedIn()) return;
  activeView = 'settings';
  settingsProfileForm.reset();
  settingsProfileMessage.style.display = 'none';
  await renderApp();
  accentColorInput.value = localStorage.getItem('jongoHubAccentColor') || '#1d4ed8';
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function returnFromAdminSettings() {
  activeView = isAdminLoggedIn() ? 'admin' : 'auth';
  await renderApp();
  if (activeView === 'admin') await renderDashboard();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function returnHome() {
  activeView = 'home';
  await renderApp();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function openAdminLogin() {
  activeView = isAdminLoggedIn() ? 'admin' : 'auth';
  await renderApp();
  if (activeView === 'admin') await renderDashboard();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function handleSettingsProfileUpdate(event) {
  event.preventDefault();
  const currentAdmin = await getCurrentAdmin();
  if (!currentAdmin) {
    showMessage(settingsProfileMessage, 'Admin session not found. Please sign in again.', true);
    return;
  }

  const name = settingsNameInput.value.trim();
  const username = settingsUsernameInput.value.trim();
  const currentPassword = settingsCurrentPasswordInput.value;
  const newPassword = settingsNewPasswordInput.value;
  const confirmPassword = settingsConfirmPasswordInput.value;

  if (!name || !username || !currentPassword) {
    showMessage(settingsProfileMessage, 'Enter your name, username, and current password.', true);
    return;
  }
  if (currentPassword !== currentAdmin.password) {
    showMessage(settingsProfileMessage, 'Current password is incorrect.', true);
    return;
  }
  if (newPassword && newPassword.length < 6) {
    showMessage(settingsProfileMessage, 'New password must be at least 6 characters.', true);
    return;
  }
  if (newPassword !== confirmPassword) {
    showMessage(settingsProfileMessage, 'New passwords do not match.', true);
    return;
  }

  const [admins, pendingAdmins] = await Promise.all([getAllAdmins(), getAllPendingAdmins()]);
  const usernameTaken = [...admins, ...pendingAdmins].some(
    (account) => account.id !== currentAdmin.id && account.username.toLowerCase() === username.toLowerCase()
  );
  if (usernameTaken) {
    showMessage(settingsProfileMessage, 'That username is already in use.', true);
    return;
  }

  await saveAdmin({
    ...currentAdmin,
    name,
    username,
    password: newPassword || currentAdmin.password
  });
  settingsCurrentPasswordInput.value = '';
  settingsNewPasswordInput.value = '';
  settingsConfirmPasswordInput.value = '';
  await renderApp();
  showMessage(settingsProfileMessage, 'Profile updated successfully.', false);
}

function toggleTheme() {
  const currentTheme = document.body.classList.contains('dark-mode') ? 'dark' : 'light';
  const nextTheme = currentTheme === 'dark' ? 'light' : 'dark';
  applyTheme(nextTheme);
}

function applyTheme(theme) {
  const isDark = theme === 'dark';
  document.body.classList.toggle('dark-mode', isDark);
  themeToggle.textContent = isDark ? '☀️ Light' : '🌙 Dark';
  settingsLightThemeBtn.setAttribute('aria-pressed', String(!isDark));
  settingsDarkThemeBtn.setAttribute('aria-pressed', String(isDark));
  localStorage.setItem('jongoHubTheme', isDark ? 'dark' : 'light');
}

function applyAccentColor(color) {
  if (!/^#[0-9a-f]{6}$/i.test(color)) return;
  document.documentElement.style.setProperty('--secondary', color);
  document.documentElement.style.setProperty('--accent', color);
  document.body.style.setProperty('--secondary', color);
  document.body.style.setProperty('--accent', color);
  localStorage.setItem('jongoHubAccentColor', color);
}

function showMessage(element, message, isError) {
  element.textContent = message;
  element.style.color = isError ? '#dc2626' : '#15803d';
  element.style.display = 'block';
}
