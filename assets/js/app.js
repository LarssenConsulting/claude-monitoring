// Inventaire Claude Code : recherche dans la page et ouverture des groupes ciblés par un lien.

(function () {
  'use strict';

  var input = document.getElementById('q');
  var entries = Array.prototype.slice.call(document.querySelectorAll('[data-search]'));
  var groups = Array.prototype.slice.call(document.querySelectorAll('details.group'));
  var sections = Array.prototype.slice.call(document.querySelectorAll('section[data-section]'));

  entries.forEach(function (el) {
    el.dataset.text = el.textContent.toLowerCase();
  });
  groups.forEach(function (group) {
    group.dataset.wasOpen = group.open ? '1' : '';
  });

  function filter() {
    var words = input.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
    var searching = words.length > 0;

    entries.forEach(function (el) {
      var text = el.dataset.text;
      el.hidden = !words.every(function (word) { return text.indexOf(word) !== -1; });
    });

    groups.forEach(function (group) {
      var counter = group.querySelector('[data-count]');
      var total = Number(counter.dataset.total);
      var shown = group.querySelectorAll('[data-search]:not([hidden])').length;
      counter.textContent = searching ? shown + ' / ' + total : String(total);
      group.hidden = shown === 0;
      group.open = searching ? shown > 0 : group.dataset.wasOpen === '1';
    });

    sections.forEach(function (section) {
      var empty = section.querySelector('.empty');
      if (empty) {
        empty.hidden = section.querySelectorAll('[data-search]:not([hidden])').length > 0;
      }
    });
  }

  function openTarget() {
    var target = location.hash ? document.getElementById(location.hash.slice(1)) : null;
    if (target && target.tagName === 'DETAILS') {
      target.open = true;
      target.scrollIntoView();
    }
  }

  if (input) {
    input.addEventListener('input', filter);
    document.addEventListener('keydown', function (event) {
      if (event.key === '/' && document.activeElement !== input) {
        event.preventDefault();
        input.focus();
      } else if (event.key === 'Escape' && document.activeElement === input) {
        input.value = '';
        filter();
      }
    });
  }

  window.addEventListener('hashchange', openTarget);
  openTarget();
})();

// Page Live : redemande la page toutes les deux secondes et remplace les blocs qui changent.
// Rien n'est demandé tant que l'onglet est masqué.

(function () {
  'use strict';

  var regions = ['.kpis', '[data-live]', '#live-time'];
  var delay = 2000;
  var timer = null;

  if (!document.querySelector('[data-live]')) {
    return;
  }

  function schedule() {
    clearTimeout(timer);
    timer = setTimeout(refresh, delay);
  }

  function refresh() {
    if (document.hidden) {
      return;
    }
    fetch(location.pathname, { cache: 'no-store' })
      .then(function (response) {
        if (!response.ok) {
          throw new Error(String(response.status));
        }
        return response.text();
      })
      .then(function (html) {
        var next = new DOMParser().parseFromString(html, 'text/html');
        regions.forEach(function (selector) {
          var fresh = next.querySelector(selector);
          var current = document.querySelector(selector);
          if (fresh && current) {
            current.replaceWith(document.importNode(fresh, true));
          }
        });
      })
      .catch(function () {
        document.getElementById('live-error').hidden = false;
      })
      .then(schedule);
  }

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
      refresh();
    }
  });
  schedule();
})();
