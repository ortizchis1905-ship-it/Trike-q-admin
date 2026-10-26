// TRIKE-Q notification header v4 — styled inbox
(function () {
    const endpoint = new URL('admin-header.php', document.currentScript?.src || document.baseURI);
    function start() {
        const header = document.querySelector('.topbar, .top');
        if (!header || header.querySelector('[data-tq-notification-version="4"]')) return;
        header.dataset.tqReady = '1';
        // Mount synchronously from the account already rendered by PHP.
        const legacy = header.querySelector('.tq-header-account, [data-tq-legacy-account], .admin-top, .admin-box, .admin-info, .admin-label') || (header.classList.contains('top') ? Array.from(header.children).reverse().find(el => el.tagName === 'DIV' && !el.querySelector('h1,h2,.page-title,button')) : null);
        const nameNode = legacy?.querySelector('.tq-account-name,.admin-name,.admin-top-text strong,.admin-copy strong,strong');
        const roleNode = legacy?.querySelector('.tq-account-role,.admin-role,.admin-top-text span,.admin-copy span');
        const plain = Array.from(header.children).find(el => el.tagName === 'SPAN');
        const seedName = (nameNode?.textContent || plain?.textContent || legacy?.textContent || 'TMU Administrator').trim();
        const seedRole = document.body.dataset.adminRole || document.documentElement.dataset.adminRole;
        let account = { name: seedName, role: seedRole === 'super_admin' ? 'Super Admin' : (roleNode?.textContent.trim() || 'TMU Administrator'), photo: legacy?.querySelector('img')?.getAttribute('src') || null };
        const cacheKey = 'trikeq.header.profile.v2';
        try {
            const saved = JSON.parse(sessionStorage.getItem(cacheKey) || 'null');
            if (!account.photo && saved?.name === account.name && saved?.role === account.role) account.photo = saved.photo;
        } catch { /* Browser storage may be unavailable. */ }
        if (legacy?.querySelector('.tq-notices')) legacy.querySelector('.tq-notices').open = false;
        header.querySelectorAll('.tq-header-account,[data-tq-legacy-account],.admin-top,.admin-box,.admin-info,.admin-label').forEach(el => el.remove());
        if (legacy?.isConnected) legacy.remove();
        if (plain) plain.remove();
        // Remove only known old account fragments, preserving headings/menu buttons.
        header.querySelectorAll('.admin-avatar,.admin-top-text').forEach(el => el.remove());
        const panel = document.createElement('div'); panel.className = 'tq-header-account'; panel.dataset.tqNotificationVersion = '4'; header.append(panel);
            document.head.querySelectorAll('style').forEach(node=>{if(node.id==='tq-notification-header-style' || node.textContent.trim().startsWith('.tq-header-account{'))node.remove();});
            const style = document.createElement('style');style.id='tq-notification-header-style';
    style.textContent = `
.tq-header-account{display:flex!important;align-items:center!important;gap:12px!important;flex-shrink:0}
.tq-header-account [hidden]{display:none!important}
.tq-account-copy{display:flex;flex-direction:column;text-align:right;gap:3px}
.tq-account-name{font:600 15px Arial,Helvetica,sans-serif;color:#1f2937}
.tq-account-role{font:13px Arial,Helvetica,sans-serif;color:#64748b}
.tq-account-avatar{width:42px;height:42px;flex-shrink:0;border-radius:50%;background:#fff7ed;color:#9a3412;display:flex;align-items:center;justify-content:center;font:600 18px Arial,Helvetica,sans-serif;overflow:hidden}
.tq-account-avatar img{width:100%;height:100%;object-fit:cover}
.tq-notices{position:relative}
.tq-notices>summary{position:relative;list-style:none;cursor:pointer;display:flex;align-items:center;justify-content:center;box-sizing:border-box;width:40px;height:40px;border:1px solid #fed7aa;border-radius:10px;color:#c2410c;background:#fff7ed;transition:background .15s,border-color .15s}
.tq-notices>summary:hover,.tq-notices[open]>summary{background:#ffedd5;border-color:#f97316}
.tq-notices>summary::-webkit-details-marker{display:none}
.tq-notice-badge{position:absolute;top:-6px;right:-7px;min-width:19px;height:19px;padding:0 4px;display:flex;align-items:center;justify-content:center;background:#dc2626;color:#fff;border:2px solid #fff;border-radius:12px;font:600 10px Arial,Helvetica,sans-serif;box-sizing:content-box}
.tq-header-account .tq-notice-menu{position:absolute;right:0;top:50px;display:flex;flex-direction:column;box-sizing:border-box;width:min(390px,calc(100vw - 24px));max-height:min(530px,78vh);padding:0;overflow:hidden;background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 12px 32px rgba(15,23,42,.16);z-index:120}
.tq-notice-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-shrink:0;margin:0;padding:15px 14px;background:#f97316}
.tq-notice-heading-title{display:inline-flex;align-items:center;gap:8px;margin:0;color:#fff;font:600 16px/22px Arial,Helvetica,sans-serif}
.tq-notice-heading-title svg{width:20px;height:20px;flex:0 0 20px}
.tq-notice-counter{padding:4px 8px;border:1px solid rgba(255,255,255,.4);border-radius:20px;background:rgba(255,255,255,.17);color:#fff;font:600 12px/17px Arial,Helvetica,sans-serif;white-space:nowrap}
.tq-notice-tools{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-shrink:0;margin:0;padding:11px 12px;border-bottom:1px solid #f1f5f9;background:#fff}
.tq-notice-tabs{display:flex;gap:3px;padding:3px;border:1px solid #e2e8f0;border-radius:9px;background:#f8fafc}
.tq-header-account .tq-notice-control{display:inline-flex;align-items:center;justify-content:center;gap:5px;box-sizing:border-box;margin:0;padding:6px 9px;min-height:30px;border:1px solid #fed7aa;border-radius:7px;background:#fff7ed;color:#9a3412;font:500 13px/18px Arial,Helvetica,sans-serif;cursor:pointer;white-space:nowrap}
.tq-header-account .tq-notice-tabs .tq-notice-control{padding:5px 10px;border-color:transparent;background:transparent;color:#64748b}
.tq-header-account .tq-notice-tabs .tq-notice-control[aria-pressed="true"]{background:#f97316;color:#fff;box-shadow:0 1px 3px #0001}
.tq-header-account .tq-notice-control:hover:not(:disabled){border-color:#f97316;background:#ffedd5;color:#9a3412}
.tq-header-account .tq-notice-tabs .tq-notice-control[aria-pressed="true"]:hover{background:#ea580c;color:#fff}
.tq-header-account .tq-notice-control:disabled{opacity:.5;cursor:default}
.tq-notice-list{display:flex;flex-direction:column;gap:7px;flex:1 1 auto;min-height:0;max-height:310px;overflow-y:auto;overscroll-behavior:contain;padding:10px;background:#f8fafc;scrollbar-width:thin;scrollbar-color:#cbd5e1 #f8fafc}
.tq-notice-list:empty{display:none}
.tq-notice-item{display:flex;align-items:flex-start;gap:8px;margin:0;padding:10px;border:1px solid #e2e8f0;border-left:3px solid #e2e8f0;border-radius:10px;background:#fff;transition:border-color .15s,box-shadow .15s}
.tq-notice-item.is-unread{border-color:#fed7aa;border-left-color:#f97316;background:#fffaf5}
.tq-notice-item:hover{border-color:#fdba74;box-shadow:0 2px 6px #00000008}
.tq-notice-event-icon{display:flex;align-items:center;justify-content:center;flex:0 0 32px;width:32px;height:32px;margin-top:1px;border-radius:9px;background:#f1f5f9;color:#64748b}
.tq-notice-event-icon svg{display:block;width:18px;height:18px}
.is-unread .tq-notice-event-icon{background:#ffedd5;color:#c2410c}
.tq-header-account .tq-notice-menu a.tq-notice-link{display:block;flex:1;min-width:0;margin:0;padding:0;border:0;color:#334155;text-decoration:none;font:14px/20px Arial,Helvetica,sans-serif}
.tq-notice-record-details{display:grid;gap:2px;margin-top:5px;color:#475569;font:13px/19px Arial,Helvetica,sans-serif}
.tq-notice-record-line{display:block;overflow-wrap:anywhere}.tq-notice-record-label{color:#64748b;font-weight:400}
.tq-notice-record-meta{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:7px}
.tq-notice-record-meta .tq-notice-date{margin:0}
.tq-notice-record-status{display:inline-block;padding:2px 6px;border-radius:5px;background:#f1f5f9;color:#475569;font:500 11px/16px Arial,Helvetica,sans-serif}
.tq-notice-record-status.is-pending{background:#fef9c3;color:#854d0e}
.tq-notice-record-status.is-resolved{background:#dcfce7;color:#166534}
.tq-notice-record-status.is-rejected{background:#fee2e2;color:#991b1b}
.tq-notice-title,.tq-notice-body,.tq-notice-date{display:block;overflow-wrap:anywhere}
.tq-notice-title{color:#334155;font-size:14px;line-height:21px;font-weight:500}
.is-unread .tq-notice-title{font-weight:600;color:#9a3412}
.tq-notice-body{margin-top:3px;color:#64748b;font-size:13px;line-height:19px;white-space:pre-line;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.tq-notice-date{margin-top:7px;color:#64748b;font-size:11px;line-height:16px}
.tq-header-account button.tq-notice-mark{display:flex;align-items:center;justify-content:center;flex:0 0 29px;box-sizing:border-box;width:29px;min-height:29px;margin:0;padding:5px;border:1px solid #fed7aa;border-radius:8px;background:#fff;color:#c2410c;cursor:pointer}
.tq-notice-mark svg{display:block;width:16px;height:16px}
.tq-notice-mark:hover:not(:disabled){background:#ffedd5;border-color:#f97316}
.tq-notice-mark:disabled{opacity:.5;cursor:default}
.tq-header-account .tq-notice-message{flex-shrink:0;margin:0;padding:24px 16px;background:#f8fafc;color:#64748b;text-align:center;font:14px/22px Arial,Helvetica,sans-serif}
.tq-header-account .tq-notice-error{flex-shrink:0;margin:10px 12px;padding:9px 10px;border:1px solid #fecaca;border-radius:8px;background:#fef2f2;color:#b91c1c;font:13px/20px Arial,Helvetica,sans-serif}
.tq-notice-footer{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-shrink:0;margin:0;padding:9px 12px;border-top:1px solid #e2e8f0;background:#fff}
.tq-notice-more{color:#64748b;font:11px/17px Arial,Helvetica,sans-serif}
.tq-header-account button:focus-visible,.tq-header-account a:focus-visible,.tq-notices>summary:focus-visible{outline:2px solid #f97316;outline-offset:2px}
@media(max-width:600px){.tq-header-account{margin-top:12px;justify-content:flex-end}.tq-account-copy{display:flex!important}}
@media(prefers-reduced-motion:reduce){.tq-notices>summary,.tq-notice-item{transition:none}}
`;
    document.head.append(style);

        const element = (tag, cls, text) => { const el = document.createElement(tag); if (cls) el.className = cls; if (text) el.textContent = text; return el; };
        const notices = element('details','tq-notices');
        const summary = element('summary'); summary.setAttribute('aria-label','Notifications');
        summary.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>';
        const menu = element('div','tq-notice-menu'); notices.append(summary,menu); panel.append(notices);
        const copy = element('div','tq-account-copy'); const name = element('span','tq-account-name'); const role = element('span','tq-account-role'); copy.append(name,role); panel.append(copy);
        const avatar = element('div','tq-account-avatar'); panel.append(avatar);
        function renderAccount(data) {
            name.textContent = data.name; role.textContent = data.role;
            const initial = data.name.slice(0,1).toUpperCase();
            if (data.photo && avatar.dataset.photo === data.photo && avatar.childNodes.length) return;
            avatar.dataset.photo = data.photo || ''; avatar.textContent = initial;
            if (data.photo) { const img = element('img'); img.src = data.photo; img.alt = data.name + ' profile picture'; img.onerror = () => { avatar.textContent = initial; }; avatar.replaceChildren(img); }
        }
        renderAccount(account);
        const badge = element('span','tq-notice-badge'); badge.hidden = true; badge.setAttribute('aria-hidden','true'); summary.append(badge);
        const heading = element('div','tq-notice-heading');
        const unreadLabel = element('span','tq-notice-counter'); unreadLabel.setAttribute('aria-live','polite');
        const headingTitle=element('strong','tq-notice-heading-title','Notifications');
        const headingIcon=element('span');headingIcon.setAttribute('aria-hidden','true');
        headingIcon.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>';
        headingTitle.prepend(headingIcon);heading.append(headingTitle,unreadLabel);
        const tools = element('div','tq-notice-tools');
        const tabs = element('div','tq-notice-tabs'); tabs.setAttribute('role','group'); tabs.setAttribute('aria-label','Notification filter');
        const allButton = element('button','tq-notice-control','All'); allButton.type='button';
        const unreadButton = element('button','tq-notice-control','Unread'); unreadButton.type='button';
        const markAll = element('button','tq-notice-control','Mark all read'); markAll.type='button';
        tabs.append(allButton,unreadButton); tools.append(tabs,markAll);
        const errorBox = element('p','tq-notice-error'); errorBox.hidden=true; errorBox.setAttribute('role','alert');
        const loadingText = element('p','tq-notice-message','Loading notifications…'); loadingText.setAttribute('role','status');
        const list = element('div','tq-notice-list'); list.setAttribute('aria-label','Recent notifications');
        const footer = element('div','tq-notice-footer');
        const moreText = element('span','tq-notice-more');
        const refreshButton = element('button','tq-notice-control','Refresh'); refreshButton.type='button';
        footer.append(moreText,refreshButton); menu.replaceChildren(heading,tools,errorBox,loadingText,list,footer);
        let working=false, loaded=false, items=[], unread=null, hasMore=false, detailsUnavailable=false, filter='all', userId='', csrf='', lastAttempt=0;
        const allowedLinks = ['complaints.php','president_requests.php'];
        function showError(message) { errorBox.textContent=message; errorBox.hidden=false; }
        function updateControls() {
            markAll.disabled=working || !loaded || !unread;
            allButton.disabled=working; unreadButton.disabled=working; refreshButton.disabled=working;
            allButton.setAttribute('aria-pressed',String(filter==='all')); unreadButton.setAttribute('aria-pressed',String(filter==='unread'));
            list.querySelectorAll('button').forEach(button=>{button.disabled=working;});
            menu.setAttribute('aria-busy',String(working));
        }
        function clearIdentity() {
            items=[]; unread=null; loaded=false; userId=''; csrf=''; badge.hidden=true; list.replaceChildren(); unreadLabel.textContent='';
            summary.setAttribute('aria-label','Notifications: refresh the page to verify your account');
            loadingText.textContent='Refresh the page to verify your account.'; loadingText.hidden=false;
        }
        function acceptIdentity(data) {
            if (typeof data.user_id!=='string' || typeof data.csrf!=='string' || !data.csrf) throw new Error('Replace both admin-header.php and admin-header.js, then refresh the page.');
            if (userId && userId!==data.user_id) { clearIdentity(); throw new Error('Your account changed. Refresh this page.'); }
            userId=data.user_id; csrf=data.csrf;
        }
        async function request(view, values) {
            const controller=new AbortController(); const timeout=setTimeout(()=>controller.abort(),20000);
            const url=new URL(endpoint); url.search=''; url.searchParams.set('view',view);
            if (!values && view==='notifications') url.searchParams.set('filter',filter);
            const options={credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},signal:controller.signal};
            if (values) {
                options.method='POST'; options.headers['Content-Type']='application/x-www-form-urlencoded;charset=UTF-8';
                options.body=new URLSearchParams({...values,csrf,user_id:userId}).toString();
            }
            try {
                const response=await fetch(url.toString(),options);
                let data; try { data=await response.json(); } catch { throw new Error('Notifications are unavailable. Check the PHP connection, then try again.'); }
                if (!response.ok) throw new Error(data.error || 'Notifications are unavailable. Try again.');
                return data;
            } catch (error) {
                if (controller.signal.aborted) throw new Error('The request timed out. Refresh notifications before retrying.');
                throw error;
            } finally { clearTimeout(timeout); }
        }
        function formatDate(value) {
            const date=new Date(value);
            if (Number.isNaN(date.getTime())) return '';
            return new Intl.DateTimeFormat('en-PH',{dateStyle:'medium',timeStyle:'short',timeZone:'Asia/Manila'}).format(date)+' (PH)';
        }
        function renderInbox() {
            if (unread!==null) {
                badge.textContent=unread>99?'99+':String(unread); badge.hidden=unread===0;
                unreadLabel.textContent=unread+' unread'; summary.setAttribute('aria-label','Notifications: '+unread+' unread');
            }
            list.replaceChildren();
            loadingText.hidden=loaded && items.length>0;
            loadingText.textContent=!loaded?'Notifications not loaded yet.':filter==='unread'?'No unread notifications.':'No notifications yet.';
            for (const item of items) {
                if (!allowedLinks.includes(item.href)) continue;
                const isUnread=item.read_at===null;
                const row=element('div','tq-notice-item'+(isUnread?' is-unread':''));
                const eventIcon=element('span','tq-notice-event-icon');eventIcon.setAttribute('aria-hidden','true');
                eventIcon.innerHTML=item.href==='president_requests.php'
                    ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="7" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M19 8v6M16 11h6"/></svg>'
                    : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 2 21h20Z"/><path d="M12 9v5M12 17v1"/></svg>';
                row.append(eventIcon);
                const link=element('a','tq-notice-link'); link.href=item.href;
                const title=element('span','tq-notice-title',item.title); link.append(title);
                const recordDetails=Array.isArray(item.details)?item.details.filter(detail=>typeof detail.label==='string' && typeof detail.value==='string' && detail.value).slice(0,3):[];
                if (recordDetails.length) {
                    const detailBox=element('span','tq-notice-record-details');
                    for(const detail of recordDetails){
                        const line=element('span','tq-notice-record-line');
                        line.append(element('span','tq-notice-record-label',detail.label+': '),document.createTextNode(detail.value));
                        detailBox.append(line);
                    }
                    link.append(detailBox);
                } else if (item.body) link.append(element('span','tq-notice-body',item.body));
                const meta=element('span','tq-notice-record-meta');
                const statuses={Pending:'is-pending',Resolved:'is-resolved',Approved:'is-resolved',Rejected:'is-rejected',Suspended:'is-rejected',Dismissed:''};
                if(typeof item.record_status==='string' && Object.prototype.hasOwnProperty.call(statuses,item.record_status)) {
                    meta.append(element('span','tq-notice-record-status '+statuses[item.record_status],'Status: '+item.record_status));
                }
                const date=formatDate(item.date);if(date)meta.append(element('span','tq-notice-date',date));
                if(meta.childNodes.length)link.append(meta);
                if (isUnread) link.addEventListener('click',async event=>{
                    if (event.button!==0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
                    event.preventDefault(); if (working) return;
                    if (await mark('mark_read',item.id)) window.location.assign(item.href);
                });
                row.append(link);
                if (isUnread) {
                    const button=element('button','tq-notice-mark'); button.type='button'; button.title='Mark as read'; button.setAttribute('aria-label','Mark as read: '+item.title);
                    button.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>';
                    button.addEventListener('click',async()=>{if(await mark('mark_read',item.id))void loadNotices();}); row.append(button);
                }
                list.append(row);
            }
            moreText.textContent=[hasMore?'Showing the latest 50.':'',detailsUnavailable?'Some record details are unavailable.':''].filter(Boolean).join(' · ');
            updateControls();
        }
        async function refreshAccount() {
            try {
                const data=await request('profile'); if (typeof data.name!=='string' || typeof data.role!=='string') return;
                acceptIdentity(data); account=data; renderAccount(data);
                // Cache only display fields, never CSRF tokens or notification contents.
                try { sessionStorage.setItem(cacheKey,JSON.stringify({name:data.name,role:data.role,photo:data.photo,user_id:data.user_id})); } catch {}
            } catch { /* Keep the account already rendered by PHP visible. */ }
        }
        async function loadNotices() {
            if (working) return;
            working=true; lastAttempt=Date.now(); errorBox.hidden=true;
            if (!loaded) { loadingText.textContent='Loading notifications…'; loadingText.hidden=false; }
            updateControls();
            try {
                const data=await request('notifications');
                if (!Array.isArray(data.items) || !Number.isInteger(data.unread) || data.unread<0) throw new Error('Replace both header files, then refresh this page.');
                acceptIdentity(data); items=data.items; unread=data.unread; hasMore=data.has_more===true; detailsUnavailable=data.details_unavailable===true; loaded=true;
                renderInbox();
            } catch (error) {
                showError(error.message || 'Notifications unavailable. Try Refresh.');
                if (!loaded) { loadingText.textContent='Notifications could not be loaded.'; loadingText.hidden=false; }
            } finally { working=false; updateControls(); }
        }
        async function mark(action,id) {
            if (working || !loaded || !userId || !csrf) return false;
            working=true; errorBox.hidden=true; updateControls();
            try {
                const data=await request('notifications',{action,...(id?{id}: {})});
                if (data.ok!==true) throw new Error('The read status could not be confirmed. Refresh before retrying.');
                return true;
            } catch (error) { showError(error.message || 'Could not save the read status. Refresh before retrying.'); return false; }
            finally { working=false; updateControls(); }
        }
        markAll.addEventListener('click',async()=>{if(await mark('mark_all_read'))void loadNotices();});
        allButton.addEventListener('click',()=>{if(working || filter==='all')return;filter='all';items=[];loaded=false;renderInbox();void loadNotices();});
        unreadButton.addEventListener('click',()=>{if(working || filter==='unread')return;filter='unread';items=[];loaded=false;renderInbox();void loadNotices();});
        refreshButton.addEventListener('click',()=>void loadNotices());
        notices.addEventListener('toggle',()=>{if(notices.open)void loadNotices();});
        document.addEventListener('click',event=>{if(!notices.contains(event.target))notices.open=false;});
        document.addEventListener('keydown',event=>{if(event.key==='Escape' && notices.open){notices.open=false;summary.focus();}});
        const refreshWhenVisible=()=>{if(!document.hidden && Date.now()-lastAttempt>5000)void loadNotices();};
        document.addEventListener('visibilitychange',refreshWhenVisible); window.addEventListener('focus',refreshWhenVisible);
        renderInbox(); void refreshAccount(); void loadNotices();
        setInterval(()=>{if(!document.hidden)void loadNotices();},60000);
    }
    if (document.readyState==='loading') document.addEventListener('DOMContentLoaded',start,{once:true}); else start();
})();