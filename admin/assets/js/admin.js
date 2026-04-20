(function () {
  'use strict';

  document.documentElement.classList.add('admin-js-ready');

  var LIST_STATE_KEY = 'admin_articles_list_state_v2';
  var isArticlesPage = document.body.classList.contains('admin-page-articles');
  // "admin-page-articles" đang dùng chung cho cả trang danh sách + trang sửa bài.
  // Khóa theo pathname để chỉ bật logic nhớ vị trí tại đúng trang danh sách.
  var isArticlesListPage = isArticlesPage && /(?:^|\/)articles\.php$/i.test(window.location.pathname || '');
  var suppressScrollSaveUntil = 0;

  function getScrollElement() {
    var content = document.querySelector('.admin-content');
    if (content && content.scrollHeight > content.clientHeight) {
      return content;
    }
    return window;
  }

  function readScrollTop() {
    var target = getScrollElement();
    if (target === window) {
      return Math.max(0, window.scrollY || window.pageYOffset || 0);
    }
    return Math.max(0, target.scrollTop || 0);
  }

  function writeScrollTop(y) {
    var nextY = Math.max(0, Number(y || 0));
    var target = getScrollElement();
    if (target === window) {
      window.scrollTo(0, nextY);
      return;
    }
    target.scrollTop = nextY;
  }

  function normalizeListQuery(search) {
    var params = new URLSearchParams(search || '');
    params.delete('list_article_id');
    params.delete('from_edit');
    var pairs = [];
    params.forEach(function (value, key) {
      pairs.push([key, value]);
    });
    pairs.sort(function (a, b) {
      if (a[0] === b[0]) {
        if (a[1] === b[1]) return 0;
        return a[1] < b[1] ? -1 : 1;
      }
      return a[0] < b[0] ? -1 : 1;
    });
    if (!pairs.length) return '';
    return pairs.map(function (pair) {
      return encodeURIComponent(pair[0]) + '=' + encodeURIComponent(pair[1]);
    }).join('&');
  }

  function getCurrentQueryParam(name) {
    var params = new URLSearchParams(window.location.search || '');
    return (params.get(name) || '').trim();
  }

  function getCurrentListState() {
    var reviewStatusRaw = getCurrentQueryParam('review_status').toLowerCase();
    var canDisappearAfterEdit = reviewStatusRaw === 'edited' || reviewStatusRaw === 'unreviewed';
    var articleId = getCurrentQueryParam('list_article_id');
    if (!articleId) {
      var rowCode = document.querySelector('.articles-table tbody tr code');
      articleId = rowCode ? (rowCode.textContent || '').trim() : '';
    }
    return {
      path: window.location.pathname,
      query: window.location.search || '',
      queryNormalized: normalizeListQuery(window.location.search || ''),
      scrollY: readScrollTop(),
      articleId: articleId,
      reviewStatus: reviewStatusRaw,
      canDisappearAfterEdit: canDisappearAfterEdit,
      savedAt: Date.now(),
    };
  }

  function saveListState(next) {
    try {
      window.sessionStorage.setItem(LIST_STATE_KEY, JSON.stringify(next));
    } catch (error) {
      // ignore storage errors
    }
  }

  function loadListState() {
    try {
      var raw = window.sessionStorage.getItem(LIST_STATE_KEY);
      if (!raw) return null;
      var parsed = JSON.parse(raw);
      if (!parsed || typeof parsed !== 'object') return null;
      return parsed;
    } catch (error) {
      return null;
    }
  }

  function clearListState() {
    try {
      window.sessionStorage.removeItem(LIST_STATE_KEY);
    } catch (error) {
      // ignore storage errors
    }
  }

  function buildEditorHref(link, articleId) {
    try {
      var url = new URL(link.getAttribute('href') || '', window.location.origin);
      if (articleId && !url.searchParams.get('list_article_id')) {
        url.searchParams.set('list_article_id', articleId);
      }
      if (!url.searchParams.get('return_mode')) {
        url.searchParams.set('return_mode', 'exact');
      }
      return url.pathname + url.search + url.hash;
    } catch (error) {
      return link.getAttribute('href') || '#';
    }
  }

  function ensureEditorLinksKeepContext() {
    var links = document.querySelectorAll('.js-open-article-editor[data-article-id]');
    if (!links.length) return;
    links.forEach(function (link) {
      var articleId = (link.getAttribute('data-article-id') || '').trim();
      link.setAttribute('href', buildEditorHref(link, articleId));
      link.addEventListener('click', function () {
        var state = getCurrentListState();
        state.articleId = articleId || state.articleId;
        saveListState(state);
      });
    });
  }

  function isReturnFromEditor() {
    return getCurrentQueryParam('from_edit') === '1';
  }

  function getReturnMode() {
    var mode = getCurrentQueryParam('return_mode').toLowerCase();
    if (mode === 'fresh') return 'fresh';
    return 'exact';
  }

  function isExactListMatch(state) {
    if (!state) return false;
    if ((window.location.pathname || '') !== (state.path || '')) return false;
    var savedNorm = typeof state.queryNormalized === 'string'
      ? state.queryNormalized
      : normalizeListQuery(state.query || '');
    var currentNorm = normalizeListQuery(window.location.search || '');
    return savedNorm === currentNorm;
  }

  function findRowByArticleId(articleId) {
    if (!articleId) return null;
    var rows = document.querySelectorAll('.articles-table tbody tr');
    var target = null;
    rows.forEach(function (row) {
      if (target) return;
      var code = row.querySelector('code');
      if (!code) return;
      if ((code.textContent || '').trim() === articleId) {
        target = row;
      }
    });
    return target;
  }

  function highlightRow(rowEl) {
    if (!rowEl) return;
    rowEl.classList.add('is-return-focus');
    window.setTimeout(function () {
      rowEl.classList.remove('is-return-focus');
    }, 2200);
  }

  function restoreListPosition() {
    if (!isArticlesListPage) return;
    var state = loadListState();
    if (!state) return;

    var savedAt = Number(state.savedAt || 0);
    if (!savedAt || Date.now() - savedAt > 1000 * 60 * 30) {
      clearListState();
      return;
    }

    var exactMatch = isExactListMatch(state);
    var fromEdit = isReturnFromEditor();
    if (!exactMatch && !fromEdit) return;
    var returnMode = getReturnMode();

    var rowEl = findRowByArticleId((state.articleId || '').trim());

    window.requestAnimationFrame(function () {
      suppressScrollSaveUntil = Date.now() + 1200;

      // Case A: quay lại theo mode exact (không save/publish) => restore đúng scroll cũ.
      if (returnMode === 'exact' && typeof state.scrollY === 'number') {
        writeScrollTop(state.scrollY);
        if (rowEl) highlightRow(rowEl);
        clearListState();
        return;
      }

      // Case B: quay lại theo mode fresh (sau save/publish) => ưu tiên dòng bài, không có thì top.
      if (rowEl && typeof rowEl.scrollIntoView === 'function') {
        rowEl.scrollIntoView({ block: 'center', behavior: 'auto' });
        highlightRow(rowEl);
      } else {
        writeScrollTop(0);
      }

      clearListState();
    });
  }

  function bindListScrollState() {
    if (!isArticlesListPage) return;
    var scrollSaveTimer = null;
    window.addEventListener('scroll', function () {
      if (Date.now() < suppressScrollSaveUntil) return;
      if (scrollSaveTimer) window.clearTimeout(scrollSaveTimer);
      scrollSaveTimer = window.setTimeout(function () {
        saveListState(getCurrentListState());
      }, 120);
    }, { passive: true });
  }

  if (isArticlesListPage) {
    ensureEditorLinksKeepContext();
    bindListScrollState();
    restoreListPosition();
  }

  // Existing instant filter behavior (kept).
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
    if (isArticlesListPage) {
      saveListState(getCurrentListState());
    }
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
    if (isArticlesListPage) {
      saveListState(getCurrentListState());
    }
    if (debounceTimer) {
      clearTimeout(debounceTimer);
    }
  });
})();
