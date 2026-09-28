(() => {
  'use strict';
  const shell = document.getElementById('appShell');
  const sidebar = document.getElementById('sidebar');
  const sidebarNav = sidebar?.querySelector('.sidebar-nav');
  const overlay = document.getElementById('sidebarOverlay');
  const sidebarScrollKey = window.RM?.sidebarScrollKey || 'rmSidebarScroll';
  const saveSidebarScroll = () => {
    if (sidebarNav) sessionStorage.setItem(sidebarScrollKey, String(sidebarNav.scrollTop));
  };
  if (sidebarNav) {
    const storedValue = sessionStorage.getItem(sidebarScrollKey);
    const storedScroll = Number(storedValue);
    if (storedValue !== null && Number.isFinite(storedScroll) && storedScroll >= 0) {
      sidebarNav.scrollTop = storedScroll;
    } else {
      sidebarNav.querySelector('a.active')?.scrollIntoView({block: 'nearest'});
    }
    let scrollFrame = 0;
    sidebarNav.addEventListener('scroll', () => {
      if (scrollFrame) return;
      scrollFrame = requestAnimationFrame(() => { scrollFrame = 0; saveSidebarScroll(); });
    }, {passive: true});
    sidebarNav.addEventListener('click', event => {
      if (event.target.closest('a')) saveSidebarScroll();
    });
    window.addEventListener('pagehide', saveSidebarScroll);
  }
  const saved = localStorage.getItem('rmSidebarCollapsed') === '1';
  if (saved && window.innerWidth > 780) shell?.classList.add('collapsed');
  document.getElementById('sidebarCollapse')?.addEventListener('click', () => {
    shell?.classList.toggle('collapsed');
    localStorage.setItem('rmSidebarCollapsed', shell?.classList.contains('collapsed') ? '1' : '0');
  });
  const toggleMobile = () => { sidebar?.classList.toggle('mobile-open'); overlay?.classList.toggle('open'); };
  document.getElementById('mobileMenu')?.addEventListener('click', toggleMobile);
  overlay?.addEventListener('click', toggleMobile);

  document.querySelectorAll('[data-dropdown]').forEach(button => button.addEventListener('click', event => {
    event.stopPropagation();
    const target = document.getElementById(button.dataset.dropdown);
    document.querySelectorAll('.dropdown.open').forEach(drop => { if (drop !== target) drop.classList.remove('open'); });
    target?.classList.toggle('open');
  }));
  document.getElementById('notificationButton')?.addEventListener('click', event => {
    event.stopPropagation(); document.getElementById('notificationDropdown')?.classList.toggle('open'); loadNotifications();
  });
  document.addEventListener('click', event => { if (!event.target.closest('.dropdown')) document.querySelectorAll('.dropdown.open').forEach(d => d.classList.remove('open')); });

  const stack = document.getElementById('toastStack');
  const dismissToast = toast => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 180); };
  document.querySelectorAll('.toast').forEach(toast => { toast.querySelector('button')?.addEventListener('click', () => dismissToast(toast)); setTimeout(() => dismissToast(toast), 6000); });
  window.rmToast = (message, type = 'success') => {
    const toast = document.createElement('div'); toast.className = `toast toast-${type}`;
    toast.innerHTML = `<i class="fa-solid fa-circle-${type === 'success' ? 'check' : 'exclamation'}"></i><span></span><button aria-label="Dismiss">×</button>`;
    toast.querySelector('span').textContent = message; toast.querySelector('button').onclick = () => dismissToast(toast); stack?.appendChild(toast); setTimeout(() => dismissToast(toast), 6000);
  };

  const confirmDialog = document.getElementById('confirmDialog'); let pendingForm = null;
  document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit', event => {
    if (form.dataset.confirmed) return; event.preventDefault(); pendingForm = form;
    document.getElementById('confirmMessage').textContent = form.dataset.confirm; confirmDialog?.showModal();
  }));
  document.getElementById('confirmCancel')?.addEventListener('click', () => { pendingForm = null; confirmDialog?.close(); });
  document.getElementById('confirmAccept')?.addEventListener('click', () => { if (!pendingForm) return; pendingForm.dataset.confirmed = '1'; pendingForm.submit(); });

  document.querySelectorAll('[data-modal-open]').forEach(button => button.addEventListener('click', event => {
    event.preventDefault();
    const dialog = document.getElementById(button.dataset.modalOpen);
    if (!dialog || dialog.open) return;
    dialog.showModal();
    requestAnimationFrame(() => dialog.querySelector('input:not([type="hidden"]), select, textarea, button')?.focus({preventScroll: true}));
  }));
  document.querySelectorAll('[data-modal-close]').forEach(button => button.addEventListener('click', () => button.closest('dialog')?.close()));
  document.querySelectorAll('dialog.modal').forEach(dialog => dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); }));
  document.querySelectorAll('form[data-ticket-form]').forEach(form => form.addEventListener('submit', () => {
    const submit = form.querySelector('button[type="submit"]');
    if (!submit || submit.disabled) return;
    submit.disabled = true;
    submit.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Creating ticket…';
  }));
  document.querySelectorAll('form[action*="/tickets/"][action$="/reply"] select[name="status"]').forEach(select => {
    select.querySelectorAll('option').forEach(option => { if (['Resolved','Closed'].includes(option.value)) option.remove(); });
    const workflowState = document.querySelector('[data-ticket-workflow-state]')?.dataset.ticketWorkflowState;
    if (['resolved','closed'].includes(workflowState || '')) select.closest('form').hidden = true;
  });

  const showBrowserNotifications = items => {
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    const seen = new Set(JSON.parse(localStorage.getItem('rmBrowserNotificationIds') || '[]'));
    items.filter(item => !item.read_at && !seen.has(String(item.id))).slice(0, 3).forEach(item => {
      const alert = new Notification(item.title, {body: item.message, tag: `rm-${item.id}`});
      alert.onclick = () => { window.focus(); location.href = item.link ? `${window.RM.baseUrl}/${item.link.replace(/^\//,'')}` : `${window.RM.baseUrl}/notifications`; };
      seen.add(String(item.id));
    });
    localStorage.setItem('rmBrowserNotificationIds', JSON.stringify([...seen].slice(-200)));
  };
  document.getElementById('enableBrowserNotifications')?.addEventListener('click', async () => {
    if (!('Notification' in window)) { window.rmToast('This browser does not support desktop notifications.', 'error'); return; }
    const permission = await Notification.requestPermission();
    window.rmToast(permission === 'granted' ? 'Browser notifications enabled.' : 'Browser notifications were not enabled.', permission === 'granted' ? 'success' : 'error');
  });

  const loadNotifications = async () => {
    const container = document.getElementById('notificationItems'); if (!container) return;
    try {
      const response = await fetch(`${window.RM.baseUrl}/notifications/feed`, {headers: {'Accept':'application/json'}});
      const data = await response.json(); const count = document.getElementById('notificationCount'); showBrowserNotifications(data.items);
      count.textContent = data.count > 99 ? '99+' : data.count; count.hidden = data.count === 0;
      container.replaceChildren();
      if (!data.items.length) { container.innerHTML = '<div class="empty-inline">You are all caught up.</div>'; return; }
      data.items.forEach(item => {
        const link = document.createElement('a'); link.className = `notification-item ${item.read_at ? '' : 'unread'}`;
        link.href = item.link ? `${window.RM.baseUrl}/${item.link.replace(/^\//,'')}` : `${window.RM.baseUrl}/notifications`;
        const icon = document.createElement('i'); icon.className = 'fa-regular fa-bell';
        const text = document.createElement('span'); const title = document.createElement('strong'); const message = document.createElement('small');
        title.textContent = item.title; message.textContent = item.message; text.append(title, message); link.append(icon, text); container.append(link);
        if (!item.read_at) link.addEventListener('click', () => navigator.sendBeacon?.(`${window.RM.baseUrl}/notifications/${item.id}/read`, new URLSearchParams({_token: window.RM.csrf})));
      });
    } catch (error) { container.innerHTML = '<div class="empty-inline">Notifications are temporarily unavailable.</div>'; }
  };
  if (document.getElementById('notificationCount')) { loadNotifications(); setInterval(loadNotifications, 60000); }

  document.addEventListener('keydown', event => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); document.querySelector('.global-search input')?.focus(); }
  });
})();
