<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if (!Auth::hasUser()) {
    redirect('/setup');
}
if (!Auth::check()) {
    redirect('/login');
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$v = (string)@filemtime(__DIR__ . '/assets/app.js');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#0E6B5C">
    <title>Shift Pay</title>
    <link rel="manifest" href="/assets/manifest.webmanifest">
    <link rel="icon" href="/assets/icons/icon-192.png">
    <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
    <link rel="stylesheet" href="/assets/fonts.css">
    <link rel="stylesheet" href="/assets/app.css?v=<?= e($v) ?>">
</head>
<body>
<div class="app">
    <div class="side">
        <div class="top">
            <div class="month">
                <button class="icon-btn" id="prev" aria-label="Previous month"><svg viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
                <h1 id="monthName"></h1>
                <button class="icon-btn" id="next" aria-label="Next month"><svg viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"/></svg></button>
            </div>
            <button class="icon-btn" id="settingsBtn" aria-label="Settings"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3h0a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8v0a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg></button>
        </div>

        <div class="warn" id="loadWarn" hidden></div>

        <section class="hero" aria-live="polite">
            <p class="label" id="heroLabel">Month payout</p>
            <p class="total" id="heroTotal">0,00&nbsp;Kč</p>
            <div class="bar" id="heroBar" aria-hidden="true"><span class="w" id="barW"></span><span class="p" id="barP"></span></div>
            <div class="split">
                <div>
                    <p class="k"><span class="swatch"></span>Worked</p>
                    <p class="v" id="workedPay">0,00&nbsp;Kč</p>
                    <p class="h" id="workedHrs">0 h</p>
                </div>
                <div>
                    <p class="k"><span class="swatch p"></span>Planned</p>
                    <p class="v" id="plannedPay">0,00&nbsp;Kč</p>
                    <p class="h" id="plannedHrs">0 h</p>
                </div>
            </div>
            <p class="bonus-line"><span>Bonuses included</span><b id="bonusTotal">0,00&nbsp;Kč</b></p>
        </section>
    </div>

    <main class="main">
        <div class="main-head">
            <h2>Shifts</h2>
            <button class="btn primary" id="addBtnDesk" type="button">Add shift</button>
        </div>
        <div class="list" id="list"></div>
        <p class="foot">Saved on your server.</p>
        <p class="foot keys">Keyboard: <kbd>N</kbd> add shift · <kbd>←</kbd> <kbd>→</kbd> change month · <kbd>Esc</kbd> close</p>
    </main>
</div>

<button class="fab" id="addBtn" type="button"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>Add shift</button>

<!-- shift sheet -->
<div class="overlay" id="shiftOverlay" hidden>
    <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="shiftTitle">
        <h2 id="shiftTitle">Add shift</h2>
        <div class="grid">
            <div class="field full">
                <label id="statusLabel">Status</label>
                <div class="seg" role="group" aria-labelledby="statusLabel">
                    <button type="button" id="stWorked" aria-pressed="true">Worked</button>
                    <button type="button" id="stPlanned" aria-pressed="false">Planned</button>
                </div>
            </div>
            <div class="field full"><label for="fDate">Date</label><input type="date" id="fDate"></div>
            <div class="field"><label for="fStart">Start</label><input type="time" id="fStart"></div>
            <div class="field"><label for="fEnd">End</label><input type="time" id="fEnd"></div>
            <div class="field"><label for="fBreak">Unpaid break (min)</label><input type="number" id="fBreak" inputmode="numeric" min="0" step="1"></div>
            <div class="field"><label for="fRate">Hourly rate</label><input type="number" id="fRate" inputmode="decimal" min="0" step="0.01"></div>
            <div class="field full"><label for="fBonus">Bonus for this shift</label><input type="number" id="fBonus" inputmode="decimal" min="0" step="0.01" placeholder="0.00"></div>
            <div class="field full"><label for="fNote">Note (optional)</label><input type="text" id="fNote" maxlength="80"></div>
        </div>
        <p class="preview" id="preview"></p>
        <p class="err" id="shiftErr" role="alert"></p>
        <div class="actions">
            <button class="btn" id="shiftCancel" type="button">Cancel</button>
            <button class="btn primary" id="shiftSave" type="button">Save shift</button>
            <button class="btn danger wide" id="shiftDelete" type="button" hidden>Delete shift</button>
        </div>
    </div>
</div>

<!-- settings sheet -->
<div class="overlay" id="setOverlay" hidden>
    <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="setTitle">
        <h2 id="setTitle">Settings</h2>
        <div class="grid">
            <div class="field"><label for="sWage">Hourly wage</label><input type="number" id="sWage" inputmode="decimal" min="0" step="0.01"></div>
            <div class="field"><label for="sSymbol">Currency symbol</label><input type="text" id="sSymbol" maxlength="4"></div>
        </div>
        <label class="check"><input type="checkbox" id="sApply"> Also update planned shifts to this wage</label>
        <p class="hint">New shifts use this wage. Worked shifts keep the rate they were saved with.</p>
        <p class="err" id="setErr" role="alert"></p>
        <div class="actions">
            <button class="btn" id="setCancel" type="button">Close</button>
            <button class="btn primary" id="setSave" type="button">Save settings</button>
        </div>

        <details>
            <summary>Data</summary>
            <div class="actions">
                <a class="btn linkbtn" href="/api/export.csv" download>Export shifts as CSV</a>
                <a class="btn linkbtn" href="/api/export.json" download>Export JSON backup</a>
                <button class="btn wide" id="importBtn" type="button">Import JSON backup</button>
            </div>
            <input type="file" id="importFile" accept="application/json,.json" hidden>
            <p class="hint" id="dataMsg" role="status"></p>
        </details>

        <details id="accountDetails">
            <summary>Account</summary>
            <h3>Signed-in devices</h3>
            <ul class="devices" id="devices"></ul>
            <h3>Change password</h3>
            <div class="grid">
                <div class="field full"><label for="pwCur">Current password</label><input type="password" id="pwCur" autocomplete="current-password"></div>
                <div class="field"><label for="pwNew">New password</label><input type="password" id="pwNew" autocomplete="new-password"></div>
                <div class="field"><label for="pwNew2">Repeat new password</label><input type="password" id="pwNew2" autocomplete="new-password"></div>
            </div>
            <p class="hint">At least 10 characters. Other devices are signed out.</p>
            <p class="err" id="pwErr" role="alert"></p>
            <div class="actions">
                <button class="btn" id="pwSave" type="button">Change password</button>
                <button class="btn danger" id="logoutBtn" type="button">Sign out</button>
            </div>
        </details>
    </div>
</div>

<script src="/assets/app.js?v=<?= e($v) ?>"></script>
</body>
</html>
