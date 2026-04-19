(function () {
  'use strict';

  document.documentElement.classList.add('admin-js-ready');

  var instantForm = document.querySelector('form[data-instant-filter="1"]');
  if (!instantForm) return;

  var searchInput = instantForm.querySelector('input[name="q"]');
  var sortSelect = instantForm.querySelector('select[name="sort"]');
  var perPageSelect = instantForm.querySelector('select[name="per_page"]');
  var reviewStatusSelect = instantForm.querySelector('select[name="review_status"]');
  var debounceTimer = null;

  function submitInstantForm() {
    if (instantForm.dataset.submitting === '1') return;
    instantForm.dataset.submitting = '1';
    if (typeof instantForm.requestSubmit === 'function') {
      instantForm.requestSubmit();
      return;
    }
    instantForm.submit();
  }

  function debounceSubmit(delayMs) {
    if (debounceTimer) {
      clearTimeout(debounceTimer);
    }
    debounceTimer = window.setTimeout(function () {
      submitInstantForm();
    }, delayMs);
  }

  if (searchInput) {
    var lastValue = searchInput.value;
    searchInput.addEventListener('input', function () {
      var nextValue = searchInput.value;
      if (nextValue === lastValue) return;
      lastValue = nextValue;
      debounceSubmit(260);
    });

    searchInput.addEventListener('keydown', function (event) {
      if (event.key !== 'Enter') return;
      event.preventDefault();
      if (debounceTimer) {
        clearTimeout(debounceTimer);
      }
      submitInstantForm();
    });
  }

  [sortSelect, perPageSelect, reviewStatusSelect].forEach(function (selectNode) {
    if (!selectNode) return;
    selectNode.addEventListener('change', function () {
      if (debounceTimer) {
        clearTimeout(debounceTimer);
      }
      submitInstantForm();
    });
  });

  instantForm.addEventListener('submit', function () {
    instantForm.dataset.submitting = '1';
    if (debounceTimer) {
      clearTimeout(debounceTimer);
    }
  });
})();
