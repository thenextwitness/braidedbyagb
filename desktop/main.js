// ============================================================
// BraidedbyAGB Admin — Windows desktop app (Electron)
// FILE: /desktop/main.js
//
// A dedicated window for the LIVE admin panel. Because it loads the website,
// every admin change appears instantly; this installer only needs re-running
// if this wrapper itself changes. Your sign-in is remembered between launches.
// Links that leave the admin (WhatsApp, email, Stripe, the public site) open in
// your normal browser.
// ============================================================
const { app, BrowserWindow, shell, Menu, dialog } = require('electron');
const path = require('path');
const fs   = require('fs');

const SITE      = 'https://braidedbyagb.co.uk';
const ADMIN_URL = SITE + '/admin';
const STATE     = () => path.join(app.getPath('userData'), 'window-state.json');

let win = null;

// One window only: launching again just brings the existing window forward.
if (!app.requestSingleInstanceLock()) {
  app.quit();
} else {
  app.on('second-instance', () => {
    if (win) { if (win.isMinimized()) win.restore(); win.focus(); }
  });
}

function loadState() {
  try { return JSON.parse(fs.readFileSync(STATE(), 'utf8')); }
  catch { return { width: 1400, height: 900, maximized: false }; }
}
function saveState() {
  if (!win) return;
  try {
    const b = win.getNormalBounds();
    fs.writeFileSync(STATE(), JSON.stringify({ ...b, maximized: win.isMaximized() }));
  } catch { /* non-fatal */ }
}

// Only the admin panel stays in the app window; everything else opens outside.
function isAdminUrl(url) {
  try {
    const u = new URL(url);
    return u.origin === SITE && (u.pathname === '/admin' || u.pathname.startsWith('/admin/'));
  } catch { return false; }
}

function createWindow() {
  const st = loadState();
  win = new BrowserWindow({
    width: st.width || 1400, height: st.height || 900,
    x: st.x, y: st.y,
    minWidth: 1000, minHeight: 680,
    title: 'BraidedbyAGB Admin',
    icon: path.join(__dirname, 'build', 'icon.png'),
    backgroundColor: '#F5F0F8',
    show: false,
    autoHideMenuBar: true,
    webPreferences: { contextIsolation: true, nodeIntegration: false, sandbox: true },
  });
  if (st.maximized) win.maximize();
  win.once('ready-to-show', () => win.show());
  win.on('close', saveState);

  // target=_blank / window.open → admin pages stay in-app, the rest go outside.
  win.webContents.setWindowOpenHandler(({ url }) => {
    if (isAdminUrl(url)) { win.loadURL(url); }
    else { shell.openExternal(url); }
    return { action: 'deny' };
  });
  // Normal link clicks that leave the admin (and its login) open outside too.
  win.webContents.on('will-navigate', (e, url) => {
    if (!isAdminUrl(url)) { e.preventDefault(); shell.openExternal(url); }
  });

  // No internet / site down → friendly retry page instead of a blank window.
  win.webContents.on('did-fail-load', (_e, code, _desc, url, isMainFrame) => {
    if (!isMainFrame || code === -3) return;   // -3 = aborted (normal on redirects)
    win.loadFile(path.join(__dirname, 'offline.html'));
  });

  win.loadURL(ADMIN_URL);
}

function buildMenu() {
  const template = [
    { label: 'App', submenu: [
      { label: 'Admin home', accelerator: 'CmdOrCtrl+H', click: () => win && win.loadURL(ADMIN_URL) },
      { label: 'Open the website', click: () => shell.openExternal(SITE) },
      { type: 'separator' },
      { role: 'reload', accelerator: 'F5' },
      { role: 'forceReload' },
      { type: 'separator' },
      { role: 'quit' },
    ]},
    { label: 'Edit', submenu: [
      { role: 'undo' }, { role: 'redo' }, { type: 'separator' },
      { role: 'cut' }, { role: 'copy' }, { role: 'paste' }, { role: 'selectAll' },
    ]},
    { label: 'View', submenu: [
      { role: 'resetZoom' }, { role: 'zoomIn' }, { role: 'zoomOut' },
      { type: 'separator' }, { role: 'togglefullscreen' },
    ]},
    { label: 'Help', submenu: [
      { label: 'About', click: () => dialog.showMessageBox(win, {
          type: 'info', title: 'BraidedbyAGB Admin',
          message: 'BraidedbyAGB Admin ' + app.getVersion(),
          detail: 'Opens your live admin panel at ' + ADMIN_URL + '.\nAdmin updates appear automatically.',
      }) },
    ]},
  ];
  Menu.setApplicationMenu(Menu.buildFromTemplate(template));
}

app.whenReady().then(() => {
  buildMenu();
  createWindow();
});
app.on('window-all-closed', () => app.quit());
