// TRIKE-Q navigation loader v4
(function () {
    // Resolve shared files from this script, not from the current page URL.
    const sourceScript = document.currentScript || Array.from(document.scripts).find(node => {
        try { return /\/admin-nav\.js$/i.test(new URL(node.src).pathname); } catch { return false; }
    });
    const projectBase = new URL('.', sourceScript && sourceScript.src ? sourceScript.src : window.location.href);
    const sharedUrl = filename => new URL(filename, projectBase).href;
    // Load the current header even when a page still includes an older script.
    const headerVersion = '20261027-5';
    const headerAlreadyIncluded=Array.from(document.scripts).some(node=>{try{return new URL(node.src).pathname===new URL('admin-header.js',projectBase).pathname;}catch{return false;}});
    if (!headerAlreadyIncluded) {
        const script = document.createElement('script');
        const headerUrl = new URL('admin-header.js', projectBase);
        headerUrl.searchParams.set('v', headerVersion);
        script.src = headerUrl.href;
        script.dataset.tqHeader = '1';
        script.dataset.tqHeaderVersion = headerVersion;
        document.head.appendChild(script);
    }
    function initializeSidebar() {
    const sidebar = document.querySelector(".sidebar");

    if (!sidebar) {
        return;
    }

    const file = (window.location.pathname.split("/").pop() || "dashboard.php").toLowerCase();

    const activeMap = {
        "dashboard.php": "dashboard",
        "drivers.php": "drivers",
        "driver_edit.php": "drivers",
        "driver_view.php": "drivers",
        "driver_qr.php": "drivers",
        "driver_profile_upload.php": "drivers",
        "register.php": "register",
        "complaints.php": "complaints",
        "toda_feedback.php": "reports",

        "renewals.php": "renewals",
        "toda.php": "toda",
        "toda_fares.php": "toda_fares",
        "president_requests.php": "president_requests",
        "reports.php": "reports",
        "settings.php": "settings",
        "admin_settings.php": "settings"
    };

    const active = activeMap[file] || "";

    const groups = (role) => [
        {
            title: "Main",
            links: [
                ["dashboard", "dashboard.php", "▣", "Dashboard"],
                ["drivers", "drivers.php", "♙", "Driver"],
                ["register", "register.php", "＋", "Register"],
                ["complaints", "complaints.php", "⚠", "Complaint / Violation"],

            ]
        },
        {
            title: "Management",
            links: [
                ["renewals", "renewals.php", "↻", "Franchise Renewal"],
                ["toda", "toda.php", "◆", "TODA Organizations"],
                ["toda_fares", "toda_fares.php", "₱", "TODA Fares"],
                ["president_requests", "president_requests.php", "◇", "President Requests"],
                ["reports", "reports.php", "▥", "Reports"],
                ["settings", "settings.php", "⚙", "Settings"]
            ]
        },
        {
            title: "Account",
            links: [
                ["logout", "logout.php", "⇥", "Logout"]
            ]
        }
    ];

    const linkMarkup = ([key, href, icon, label]) => {
        const classes = key === active ? " class=\"active\" aria-current=\"page\"" : "";
        return `<a href="${href}"${classes}><span class="icon">${icon}</span>${label}</a>`;
    };

    const renderSidebar = (role) => {
        sidebar.innerHTML = `
            <img data-tq-system-logo alt="System logo" width="72" height="72" decoding="async" style="display:block;width:72px;height:72px;min-width:72px;max-width:72px;object-fit:contain;margin:0 auto 10px;flex:0 0 72px">
            <div class="logo">TRIKE-Q</div>
            <div class="logo-subtitle">TMU ADMIN SYSTEM</div>
            ${groups(role).map((group) => `
                <div class="menu-title">${group.title}</div>
                ${group.links.map(linkMarkup).join("")}
            `).join("")}
        `;
        refreshLogo();
    };

    let logoRequest = 0;
    function refreshLogo() {
        const logo = sidebar.querySelector('[data-tq-system-logo]');
        if (!logo) return;
        const url = new URL('system-logo.php', projectBase);
        url.searchParams.set('v', String(Date.now()) + '-' + (++logoRequest));
        logo.src = url.href;
    }
    // A browser may restore an old page and its old image from its back/forward cache.
    window.addEventListener('pageshow', event => { if (event.persisted) refreshLogo(); });

    const updateVisibleRoleLabels = (label) => {
        document
            .querySelectorAll(".admin-role, .admin-label .muted, .admin-info .muted")
            .forEach((element) => {
                const text = element.textContent.trim();

                if (
                    text === "TMU Admin" ||
                    text === "TMU Administrator" ||
                    text === "Administrator" ||
                    text === "Super Admin"
                ) {
                    element.textContent = label;
                }
            });
    };

    const applyRole = (role, label) => {
        document.documentElement.dataset.adminRole = role;

        if (document.body) {
            document.body.dataset.adminRole = role;
        }

        renderSidebar(role);
        updateVisibleRoleLabels(label);
    };

    applyRole('tmu_admin', 'TMU Administrator');
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeSidebar, { once: true });
    } else {
        initializeSidebar();
    }
})();
