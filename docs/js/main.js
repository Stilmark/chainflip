function updateFooterDate() {
  fetch('data/digest.json')
    .then(function(r) { return r.ok ? r.json() : Promise.reject(r.status); })
    .then(function(d) {
      if (!d.generated_at) return;
      var dt = new Date(d.generated_at);
      var pad = function(n) { return String(n).padStart(2, '0'); };
      var formatted = dt.getFullYear() + '-' +
        pad(dt.getMonth() + 1) + '-' +
        pad(dt.getDate()) + ' ' +
        pad(dt.getHours()) + ':' +
        pad(dt.getMinutes()) + ':' +
        pad(dt.getSeconds());
      var el = document.getElementById('footer-updated');
      if (el) el.textContent = formatted;
    })
    .catch(function() { /* digest not available — footer stays static */ });
}

function copySelectedTextToClipboard() {
  try {
    var selection = window.getSelection();
    if (!selection) return;
    var text = selection.toString().trim();
    if (!text) return;
    if (!navigator.clipboard || !navigator.clipboard.writeText) return;
    navigator.clipboard.writeText(text).catch(function() { /* ignore permission failures */ });
  } catch (e) {
    // no-op
  }
}

document.addEventListener('DOMContentLoaded', function() {
  updateFooterDate();

  // Global UX helper: any selected text is copied to clipboard.
  document.addEventListener('mouseup', copySelectedTextToClipboard);
  document.addEventListener('keyup', function() {
    copySelectedTextToClipboard();
  });

  // Initialize DataTables on all tables with class 'data-table'
  const tables = document.querySelectorAll('table.data-table');
  tables.forEach(function(table) {
    new DataTable(table, {
      paging: false,
      searching: false,
      info: false,
      order: [],
      columnDefs: [
        { targets: '_all', orderable: true }
      ]
    });
  });

  // Set active nav link based on current page
  const currentPage = window.location.pathname.split('/').pop() || 'index.html';
  const navLinks = document.querySelectorAll('nav .nav-links a');
  navLinks.forEach(function(link) {
    const href = link.getAttribute('href');
    if (href === currentPage || (currentPage === '' && href === 'index.html')) {
      link.classList.add('active');
    }
  });

  // Sub-nav section toggle
  const subNavLinks = document.querySelectorAll('.sub-nav a');
  const sections = document.querySelectorAll('.container > section.card');

  function showSection(targetId) {
    sections.forEach(function(section) {
      if (section.id === targetId) {
        section.classList.add('active');
        section.classList.remove('hidden');
      } else {
        section.classList.remove('active');
        section.classList.add('hidden');
      }
    });

    subNavLinks.forEach(function(link) {
      const href = link.getAttribute('href');
      if (href === '#' + targetId) {
        link.classList.add('active');
      } else {
        link.classList.remove('active');
      }
    });
  }

  if (subNavLinks.length > 0 && sections.length > 0) {
    // Get initial section from hash or first sub-nav link
    let initialTarget = window.location.hash.replace('#', '') || '';
    if (!initialTarget) {
      const firstLink = subNavLinks[0];
      if (firstLink) {
        initialTarget = firstLink.getAttribute('href').replace('#', '');
      }
    }
    if (initialTarget) {
      showSection(initialTarget);
    }

    // Handle sub-nav clicks
    subNavLinks.forEach(function(link) {
      link.addEventListener('click', function(e) {
        e.preventDefault();
        const targetId = this.getAttribute('href').replace('#', '');
        showSection(targetId);
        history.pushState(null, '', '#' + targetId);
      });
    });

    // Handle browser back/forward
    window.addEventListener('popstate', function() {
      const targetId = window.location.hash.replace('#', '') || subNavLinks[0].getAttribute('href').replace('#', '');
      showSection(targetId);
    });
  }
});
