(function () {
  var PAGE_SIZE = 12;

  function defaultFeatureImage() {
    var root = (document.body && document.body.dataset && document.body.dataset.root) || '';
    return root + 'assets/images/content/chia_se_kien_thuc_tai_lieu_KeToanDieuTam.jpg';
  }

  function getHubData() {
    var script = document.getElementById('hub-data');
    if (!script) return null;
    try {
      return JSON.parse(script.textContent);
    } catch (error) {
      console.error('Hub data lỗi định dạng', error);
      return null;
    }
  }

  function loadHubData() {
    var inlineData = getHubData();
    if (!inlineData) return Promise.resolve(null);
    if (!inlineData.dataUrl) return Promise.resolve(inlineData);
    return fetch(inlineData.dataUrl)
      .then(function (response) {
        if (!response.ok) throw new Error('Không tải được hub data');
        return response.json();
      })
      .then(function (remote) {
        return Object.assign({}, inlineData, remote);
      })
      .catch(function (error) {
        console.error('Hub data tải lỗi', error);
        return inlineData;
      });
  }

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function (char) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char];
    });
  }

  function formatDateLabel(dateIso) {
    if (!dateIso) return '';
    var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(dateIso);
    if (!match) return dateIso;
    return match[3] + '/' + match[2] + '/' + match[1];
  }

	  function readQueryState() {
	    var params = new URLSearchParams(location.search);
	    var page = parseInt(params.get('page') || '1', 10);
	    return {
	      q: params.get('q') || '',
	      kind: params.get('kind') || '',
	      lv1: params.get('lv1') || '',
	      lv2: params.get('lv2') || '',
	      page: Number.isFinite(page) && page > 0 ? page : 1
	    };
	  }

  function hasExplicitPageParam() {
    return new URLSearchParams(location.search).has('page');
  }

	  function buildSearch(state) {
	    var params = new URLSearchParams();
	    if (state.q) params.set('q', state.q);
	    if (state.kind) params.set('kind', state.kind);
	    if (state.lv1) params.set('lv1', state.lv1);
	    if (state.lv2) params.set('lv2', state.lv2);
    if (state.page && state.page > 1) params.set('page', String(state.page));
    var query = params.toString();
    return query ? ('?' + query) : '';
  }

  function updateUrl(url, mode) {
    if (mode === 'push') history.pushState(null, '', url);
    else history.replaceState(null, '', url);
  }

  function absoluteUrl(pathAndSearch) {
    try {
      return new URL(pathAndSearch, location.href).href;
    } catch (error) {
      return pathAndSearch;
    }
  }

  function upsertMeta(name, content) {
    var selector = 'meta[name="' + name + '"]';
    var meta = document.head && document.head.querySelector ? document.head.querySelector(selector) : document.querySelector(selector);
    if (!meta) {
      meta = document.createElement('meta');
      meta.setAttribute('name', name);
      document.head.appendChild(meta);
    }
    meta.setAttribute('content', content);
  }

  function upsertLink(rel, href, key) {
    var selector = key === 'canonical'
      ? 'link[rel="canonical"], link[data-hub-seo="canonical"]'
      : 'link[data-hub-seo="' + key + '"]';
    var link = document.head && document.head.querySelector ? document.head.querySelector(selector) : document.querySelector(selector);

    if (!href) {
      if (link && link.parentNode && typeof link.parentNode.removeChild === 'function') link.parentNode.removeChild(link);
      return;
    }

    if (!link) {
      link = document.createElement('link');
      link.setAttribute('rel', rel);
      link.setAttribute('data-hub-seo', key);
      document.head.appendChild(link);
    }
    link.setAttribute('href', absoluteUrl(href));
  }

  function appendQueryParam(url, key, value) {
    if (!value) return url;
    var parts = String(url).split('#');
    var path = parts[0];
    var hash = parts[1] ? ('#' + parts[1]) : '';
    var joiner = path.indexOf('?') === -1 ? '?' : '&';
    return path + joiner + encodeURIComponent(key) + '=' + encodeURIComponent(value) + hash;
  }

  function normalizeState(state, data) {
    var next = {
      q: (state.q || '').trim(),
      kind: state.kind || '',
      lv1: state.lv1 || '',
	      lv2: state.lv2 || '',
	      page: Math.max(1, Number(state.page || 1))
	    };

    if (data.section !== 'thu-vien') {
      next.kind = '';
    } else if (next.kind) {
      var validKind = (data.libraryKinds || []).some(function (item) { return item.key === next.kind; });
      if (!validKind) next.kind = '';
    }

    var scopedTaxonomy = data.taxonomy || [];
    if (data.section === 'thu-vien' && next.kind) {
      scopedTaxonomy = buildTaxonomyFromArticles((data.articles || []).filter(function (article) {
        return article.library_kind_key === next.kind;
      }));
    }

    var lv1 = scopedTaxonomy.find(function (item) { return item.key === next.lv1; });
    if (!lv1) {
      next.lv1 = '';
      next.lv2 = '';
      return next;
    }

    if (next.lv2) {
      var lv2 = (lv1.children || []).find(function (item) { return item.key === next.lv2; });
      if (!lv2) next.lv2 = '';
    }
    return next;
  }

  function collectPages(totalPages, currentPage) {
    var pages = [];
    if (totalPages <= 7) {
      for (var i = 1; i <= totalPages; i += 1) pages.push(i);
      return pages;
    }

    pages.push(1);
    var start = Math.max(2, currentPage - 1);
    var end = Math.min(totalPages - 1, currentPage + 1);

    if (start > 2) pages.push('ellipsis-left');
    for (var page = start; page <= end; page += 1) pages.push(page);
    if (end < totalPages - 1) pages.push('ellipsis-right');
    pages.push(totalPages);
    return pages;
  }

  function buildTaxonomyFromArticles(articles) {
    var map = {};
    (articles || []).forEach(function (article) {
      if (!article.topic_lv1_key) return;
      if (!map[article.topic_lv1_key]) {
        map[article.topic_lv1_key] = {
          key: article.topic_lv1_key,
          label: article.topic_lv1_label,
          count: 0,
          childrenMap: {}
        };
      }
      var lv1 = map[article.topic_lv1_key];
      lv1.count += 1;
      if (article.topic_lv2_key) {
        if (!lv1.childrenMap[article.topic_lv2_key]) {
          lv1.childrenMap[article.topic_lv2_key] = {
            key: article.topic_lv2_key,
            label: article.topic_lv2_label,
            count: 0
          };
        }
        lv1.childrenMap[article.topic_lv2_key].count += 1;
      }
    });

    return Object.keys(map).map(function (key) {
      var lv1 = map[key];
      return {
        key: lv1.key,
        label: lv1.label,
        count: lv1.count,
        children: Object.keys(lv1.childrenMap).map(function (childKey) {
          return lv1.childrenMap[childKey];
        }).sort(function (a, b) {
          return b.count - a.count || a.label.localeCompare(b.label, 'vi');
        })
      };
    }).sort(function (a, b) {
      return b.count - a.count || a.label.localeCompare(b.label, 'vi');
    });
  }

  function initHubPage(data) {
    if (!data) return;

    var input = document.getElementById('hubSearch');
    var count = document.getElementById('hubCount');
    var inlineCount = document.getElementById('hubInlineCount');
    var activeFilter = document.getElementById('hubActiveFilter');
    var results = document.getElementById('hubResults');
    var filters = document.getElementById('hubFilters');
    var reset = document.getElementById('hubResetFilters');
    var chips = document.getElementById('hubChips');
    var primaryFilters = document.getElementById('hubPrimaryFilters');
    var toggleFilters = document.getElementById('hubToggleFilters');
    var closeFilters = document.getElementById('hubCloseFilters');
    var filterPanel = document.getElementById('hubFilterPanel');
    var filterBackdrop = document.getElementById('hubFilterBackdrop');
    var pagination = document.getElementById('hubPagination');

    if (!input || !results) return;

    var state = normalizeState(readQueryState(), data);
	    if (!state.q && !state.kind && !state.lv1 && !state.lv2 && !hasExplicitPageParam()) {
	      state.page = Math.max(1, Number(data.currentPage || 1));
	    }
    input.value = state.q;
    var baseTitle = document.title;
    var baseDescriptionMeta = document.querySelector('meta[name="description"]');
    var baseDescription = baseDescriptionMeta ? (baseDescriptionMeta.getAttribute('content') || '') : '';
    var heroKicker = document.querySelector('.catalog-kicker');
    var heroTitle = document.querySelector('.catalog-title');
    var heroDescription = document.querySelector('.catalog-description');
    var baseHero = {
      kickerHtml: heroKicker ? heroKicker.innerHTML : '',
      title: heroTitle ? heroTitle.textContent : '',
      description: heroDescription ? heroDescription.textContent : '',
      searchPlaceholder: input.getAttribute('placeholder') || ''
    };

    var returnStorageKey = 'kdt:return:' + data.section;
    var restoreFlagKey = returnStorageKey + ':restore';

    function getLibraryKind(key) {
      return (data.libraryKinds || []).find(function (item) { return item.key === key; }) || null;
    }

    function getHeroConfig() {
      if (!(data.section === 'thu-vien' && state.kind)) return baseHero;
      if (state.kind === 'huong-dan') {
        return {
          kickerHtml: '<i class="fa-solid fa-compass-drafting"></i> Quy trình & nghiệp vụ',
          title: 'Hướng dẫn',
          description: 'Kho bài hướng dẫn nghiệp vụ, kê khai, hạch toán và xử lý tình huống giúp bạn làm đúng và làm nhanh hơn.',
          searchPlaceholder: 'Tìm bài hướng dẫn, cách làm, quy trình...'
        };
      }
      if (state.kind === 'bieu-mau') {
        return {
          kickerHtml: '<i class="fa-regular fa-file-lines"></i> Mẫu biểu & hồ sơ',
          title: 'Biểu mẫu',
          description: 'Kho biểu mẫu kế toán, thuế, lao động và hồ sơ doanh nghiệp để tra cứu, tải về và áp dụng ngay.',
          searchPlaceholder: 'Tìm biểu mẫu, tờ khai, mẫu hồ sơ...'
        };
      }
      if (state.kind === 'cong-cu') {
        return {
          kickerHtml: '<i class="fa-solid fa-screwdriver-wrench"></i> Phần mềm & file hỗ trợ',
          title: 'Công cụ',
          description: 'Kho công cụ, file Excel, phần mềm kế toán và tài nguyên hỗ trợ để triển khai công việc thực tế thuận tiện hơn.',
          searchPlaceholder: 'Tìm công cụ, Excel, HTKK, MISA...'
        };
      }
      return baseHero;
    }

    function updateHeroContext() {
      var hero = getHeroConfig();
      if (heroKicker) heroKicker.innerHTML = hero.kickerHtml;
      if (heroTitle) heroTitle.textContent = hero.title;
      if (heroDescription) heroDescription.textContent = hero.description;
      input.setAttribute('placeholder', hero.searchPlaceholder || baseHero.searchPlaceholder);
    }

    function getKindScopedArticles() {
      if (!(data.section === 'thu-vien' && state.kind)) return data.articles || [];
      return (data.articles || []).filter(function (article) {
        return article.library_kind_key === state.kind;
      });
    }

    function getScopedTaxonomy() {
      if (!(data.section === 'thu-vien' && state.kind)) return data.taxonomy || [];
      return buildTaxonomyFromArticles(getKindScopedArticles());
    }

    function getLibrarySubgroupOptions() {
      if (!(data.section === 'thu-vien' && state.kind)) return [];
      var scopedArticles = getKindScopedArticles();
      if (state.kind === 'huong-dan') {
        return getScopedTaxonomy().map(function (lv1) {
          return { mode: 'lv1', key: lv1.key, label: lv1.label, count: lv1.count };
        });
      }
      var lv2Counts = {};
      scopedArticles.forEach(function (article) {
        if (!article.topic_lv2_key) return;
        if (!lv2Counts[article.topic_lv2_key]) {
          lv2Counts[article.topic_lv2_key] = {
            mode: 'lv2',
            key: article.topic_lv2_key,
            label: article.topic_lv2_label,
            count: 0
          };
        }
        lv2Counts[article.topic_lv2_key].count += 1;
      });
      return Object.keys(lv2Counts).map(function (key) {
        return lv2Counts[key];
      }).sort(function (a, b) {
        return b.count - a.count || a.label.localeCompare(b.label, 'vi');
      });
    }

    function getLv1(key) {
      return getScopedTaxonomy().find(function (item) { return item.key === key; }) || null;
    }

	    function getLv2(lv1Key, lv2Key) {
	      if (lv1Key) {
	        var lv1 = getLv1(lv1Key);
	        if (!lv1) return null;
	        return (lv1.children || []).find(function (item) { return item.key === lv2Key; }) || null;
	      }
	      var taxonomy = getScopedTaxonomy();
	      for (var i = 0; i < taxonomy.length; i += 1) {
	        var hit = (taxonomy[i].children || []).find(function (item) { return item.key === lv2Key; });
	        if (hit) return hit;
	      }
	      return null;
	    }

    function stateLabel() {
	      if (state.lv2) {
	        var lv2 = getLv2(state.lv1, state.lv2);
	        return lv2 ? lv2.label : 'Tất cả bài viết';
	      }
	      if (state.lv1) {
	        var lv1 = getLv1(state.lv1);
	        return lv1 ? lv1.label : 'Tất cả bài viết';
	      }
	      if (state.kind) {
	        var kind = getLibraryKind(state.kind);
	        return kind ? kind.label : 'Tất cả bài viết';
	      }
	      return 'Tất cả bài viết';
	    }

	    function filterArticles() {
	      var needle = state.q.trim().toLowerCase();
	      return data.articles.filter(function (article) {
	        if (state.kind && article.library_kind_key !== state.kind) return false;
	        if (state.lv1 && article.topic_lv1_key !== state.lv1) return false;
        if (state.lv2 && article.topic_lv2_key !== state.lv2) return false;
        if (!needle) return true;

	        var haystack = [
	          article.title,
	          article.excerpt,
	          article.badge_label,
	          article.library_kind_label,
	          article.topic_lv1_label,
	          article.topic_lv2_label,
	          (article.tags || []).join(' ')
        ].join(' ').toLowerCase();

        return haystack.indexOf(needle) !== -1;
      });
    }

    function saveReturnState() {
      try {
        sessionStorage.setItem(returnStorageKey, JSON.stringify({
          url: location.pathname + location.search,
          scrollY: window.scrollY || window.pageYOffset || 0
        }));
      } catch (error) {
        /* noop */
      }
    }

    function currentReturnUrl() {
      return location.pathname + location.search;
    }

    function buildArticleHref(articleHref) {
      return appendQueryParam(articleHref, 'from', currentReturnUrl());
    }

	    function hasFilters() {
	      return Boolean(state.q || state.kind || state.lv1 || state.lv2);
	    }

	    function buildStateUrl(nextState) {
	      var filtered = Boolean(nextState.q || nextState.kind || nextState.lv1 || nextState.lv2);
	      if (!filtered) {
	        return data.pageMap[String(nextState.page)] || data.pageMap['1'] || data.sectionRootHref || location.pathname;
	      }
	      return (data.sectionRootHref || location.pathname) + buildSearch(nextState);
    }

    function restoreScrollIfNeeded() {
      try {
        if (sessionStorage.getItem(restoreFlagKey) !== '1') return;
        var raw = sessionStorage.getItem(returnStorageKey);
        sessionStorage.removeItem(restoreFlagKey);
        if (!raw) return;
        var payload = JSON.parse(raw);
        if (!payload || payload.url !== location.pathname + location.search) return;
        setTimeout(function () {
          window.scrollTo(0, Number(payload.scrollY || 0));
        }, 0);
      } catch (error) {
        /* noop */
      }
    }

    function openFilterPanel() {
      if (!filterPanel || !filterBackdrop) return;
      filterBackdrop.hidden = false;
      document.body.classList.add('is-filter-open');
      filterPanel.setAttribute('aria-hidden', 'false');
    }

    function closeFilterPanel() {
      if (!filterPanel || !filterBackdrop) return;
      document.body.classList.remove('is-filter-open');
      filterPanel.setAttribute('aria-hidden', 'true');
      filterBackdrop.hidden = true;
    }

    function renderChips() {
      if (!chips) return;

      var htmlParts = [];
      if (state.q) {
        htmlParts.push('<span class="catalog-chip"><i class="fa-solid fa-magnifying-glass"></i>' + escapeHtml(state.q) + '</span>');
      }
      if (state.kind) {
        var kind = getLibraryKind(state.kind);
        if (kind) htmlParts.push('<span class="catalog-chip"><i class="fa-solid fa-layer-group"></i>' + escapeHtml(kind.label) + '</span>');
      }
	      if (state.lv1) {
	        var lv1 = getLv1(state.lv1);
	        if (lv1) htmlParts.push('<span class="catalog-chip"><i class="fa-solid fa-folder-open"></i>' + escapeHtml(lv1.label) + '</span>');
      }
      if (state.lv2) {
        var lv2 = getLv2(state.lv1, state.lv2);
        if (lv2) htmlParts.push('<span class="catalog-chip"><i class="fa-solid fa-tag"></i>' + escapeHtml(lv2.label) + '</span>');
      }

      chips.innerHTML = htmlParts.join('');
      chips.style.display = htmlParts.length ? 'flex' : 'none';
    }

	    function renderPrimaryFilters() {
	      if (!primaryFilters) return;

	      primaryFilters.className = 'catalog-primary-row';
	      var htmlParts = [];
	      if (data.section === 'thu-vien' && (data.libraryKinds || []).length) {
	        if (!state.kind) {
	          primaryFilters.classList.add('catalog-primary-row--library');
	          data.libraryKinds.forEach(function (kind) {
	            htmlParts.push(
	              '<button class="catalog-primary-btn catalog-primary-btn--library" type="button" data-kind="' + escapeHtml(kind.key) + '">' +
	                '<span class="catalog-primary-btn__icon"><i class="fa-solid ' + escapeHtml(kind.icon || 'fa-layer-group') + '"></i></span>' +
	                '<span class="catalog-primary-btn__body">' +
	                  '<strong>' + escapeHtml(kind.label) + '</strong>' +
	                  '<small>' + escapeHtml(kind.description || '') + '</small>' +
	                '</span>' +
	                '<span class="catalog-primary-btn__count">' + kind.count + ' bài</span>' +
	              '</button>'
	            );
	          });
	        } else {
	          var activeKind = getLibraryKind(state.kind);
          var subgroups = getLibrarySubgroupOptions();
          htmlParts.push(
            '<button class="catalog-primary-btn catalog-primary-btn--back" type="button" data-kind-reset="1">' +
              '<span><i class="fa-solid fa-arrow-left"></i> Thư viện</span>' +
            '</button>'
          );
          if (activeKind) {
            htmlParts.push(
              '<span class="catalog-primary-label">' + escapeHtml(activeKind.label) + '</span>'
            );
          }
          subgroups.forEach(function (item) {
            var active = (item.mode === 'lv1' && state.lv1 === item.key && !state.lv2) ||
              (item.mode === 'lv2' && state.lv2 === item.key);
            htmlParts.push(
              '<button class="catalog-primary-btn' + (active ? ' is-active' : '') + '" type="button" data-scope-mode="' + item.mode + '" data-scope-key="' + escapeHtml(item.key) + '">' +
                '<span>' + escapeHtml(item.label) + '</span><small>' + item.count + '</small>' +
              '</button>'
            );
          });
        }
      } else {
        htmlParts.push(
          '<button class="catalog-primary-btn' + (!state.lv1 ? ' is-active' : '') + '" type="button" data-lv1="">' +
            '<span>Tất cả</span><small>' + data.articles.length + '</small>' +
          '</button>'
	        );
	        data.taxonomy.forEach(function (lv1) {
	          htmlParts.push(
	            '<button class="catalog-primary-btn' + (state.lv1 === lv1.key ? ' is-active' : '') + '" type="button" data-lv1="' + escapeHtml(lv1.key) + '">' +
	              '<span>' + escapeHtml(lv1.label) + '</span><small>' + lv1.count + '</small>' +
	            '</button>'
	          );
	        });
	      }

      primaryFilters.innerHTML = htmlParts.join('');
      primaryFilters.querySelectorAll('.catalog-primary-btn').forEach(function (button) {
        button.addEventListener('click', function () {
          if (button.dataset.kindReset) {
            state.kind = '';
            state.lv1 = '';
            state.lv2 = '';
          } else if (button.dataset.scopeMode === 'lv1') {
            state.lv1 = button.dataset.scopeKey || '';
            state.lv2 = '';
          } else if (button.dataset.scopeMode === 'lv2') {
            state.lv2 = button.dataset.scopeKey || '';
          } else if (button.dataset.kind !== undefined) {
            state.kind = button.dataset.kind || '';
            state.lv1 = '';
            state.lv2 = '';
          } else {
            state.lv1 = button.dataset.lv1 || '';
            state.lv2 = '';
          }
          state.page = 1;
	          updateUrl(buildStateUrl(state), 'push');
	          renderAll();
        });
      });
    }

    function renderAdvancedFilters() {
      if (!filters) return;

      var scopedTaxonomy = getScopedTaxonomy();
      var groups = state.lv1
        ? scopedTaxonomy.filter(function (item) { return item.key === state.lv1; })
        : scopedTaxonomy;

      filters.innerHTML = groups.map(function (lv1) {
        var allActive = state.lv1 === lv1.key && !state.lv2;
        var allBtn = '' +
          '<button class="catalog-filter-btn' + (allActive ? ' is-active' : '') + '" type="button" data-lv1="' + escapeHtml(lv1.key) + '" data-lv2="">' +
            '<span>Tất cả ' + escapeHtml(lv1.label) + '</span><small>' + lv1.count + '</small>' +
          '</button>';

        var childBtns = (lv1.children || []).map(function (lv2) {
          var active = state.lv2 === lv2.key ? ' is-active' : '';
          return '' +
            '<button class="catalog-filter-btn' + active + '" type="button" data-lv1="' + escapeHtml(lv1.key) + '" data-lv2="' + escapeHtml(lv2.key) + '">' +
              '<span>' + escapeHtml(lv2.label) + '</span><small>' + lv2.count + '</small>' +
            '</button>';
        }).join('');

        return '' +
          '<section class="catalog-filter-group">' +
            '<h4>' + escapeHtml(lv1.label) + ' <small>' + lv1.count + ' bài</small></h4>' +
            '<div class="catalog-filter-list">' + allBtn + childBtns + '</div>' +
          '</section>';
      }).join('');

      filters.querySelectorAll('.catalog-filter-btn').forEach(function (button) {
        button.addEventListener('click', function () {
          state.lv1 = button.dataset.lv1 || '';
          state.lv2 = button.dataset.lv2 || '';
          state.page = 1;
          updateUrl(buildStateUrl(state), 'push');
          renderAll();
          closeFilterPanel();
        });
      });
    }

	    function buildPageHref(nextPage) {
	      return buildStateUrl({
	        q: state.q,
	        kind: state.kind,
	        lv1: state.lv1,
	        lv2: state.lv2,
	        page: nextPage
	      });
    }

    function updateSeo(totalPages, itemCount) {
      var filtered = hasFilters();
      var canonicalHref = buildStateUrl({ q: '', lv1: '', lv2: '', page: Math.max(1, state.page) });
      var title = baseTitle;
      var description = baseDescription;

      if (!filtered && state.page > 1) {
        canonicalHref = buildPageHref(state.page);
        title = baseTitle + ' - Trang ' + state.page;
        if (description) description = description + ' Trang ' + state.page + '.';
      } else if (filtered) {
        canonicalHref = data.sectionRootHref || location.pathname;
        var label = state.q ? ('Tìm: ' + state.q) : stateLabel();
        title = baseTitle + ' - ' + label;
      }

      document.title = title;
      if (baseDescriptionMeta && description) {
        baseDescriptionMeta.setAttribute('content', description);
      }

      upsertMeta('robots', filtered ? 'noindex,follow' : 'index,follow');
      upsertLink('canonical', canonicalHref, 'canonical');

      if (filtered || totalPages <= 1 || !itemCount) {
        upsertLink('prev', null, 'prev');
        upsertLink('next', null, 'next');
        return;
      }

      upsertLink('prev', state.page > 1 ? buildPageHref(state.page - 1) : null, 'prev');
      upsertLink('next', state.page < totalPages ? buildPageHref(state.page + 1) : null, 'next');
    }

    function renderPagination(totalPages, totalItems) {
      if (!pagination) return;

      if (!totalItems || totalPages <= 1) {
        pagination.innerHTML = '';
        pagination.style.display = 'none';
        return;
      }

      var pages = collectPages(totalPages, state.page);
      var htmlParts = [];

      htmlParts.push(
        '<a class="catalog-pagination__btn catalog-pagination__nav' + (state.page === 1 ? ' is-disabled' : '') + '"' +
          ' href="' + escapeHtml(buildPageHref(Math.max(1, state.page - 1))) + '"' +
          (state.page === 1 ? ' aria-disabled="true" tabindex="-1"' : '') +
          ' aria-label="Trang trước">' +
          '<i class="fa-solid fa-angle-left"></i>' +
        '</a>'
      );

      pages.forEach(function (item) {
        if (typeof item === 'string') {
          htmlParts.push('<span class="catalog-pagination__ellipsis">…</span>');
          return;
        }
        htmlParts.push(
          '<a class="catalog-pagination__btn' + (item === state.page ? ' is-active' : '') + '"' +
            ' href="' + escapeHtml(buildPageHref(item)) + '"' +
            (item === state.page ? ' aria-current="page"' : '') + '>' +
            item +
          '</a>'
        );
      });

      htmlParts.push(
        '<a class="catalog-pagination__btn catalog-pagination__nav' + (state.page === totalPages ? ' is-disabled' : '') + '"' +
          ' href="' + escapeHtml(buildPageHref(Math.min(totalPages, state.page + 1))) + '"' +
          (state.page === totalPages ? ' aria-disabled="true" tabindex="-1"' : '') +
          ' aria-label="Trang sau">' +
          '<i class="fa-solid fa-angle-right"></i>' +
        '</a>'
      );

      pagination.innerHTML = htmlParts.join('');
      pagination.style.display = 'flex';
    }

    function renderResults() {
      var items = filterArticles();
      var totalPages = Math.max(1, Math.ceil(items.length / PAGE_SIZE));

      if (state.page > totalPages) {
        state.page = totalPages;
        updateUrl(buildStateUrl(state), 'replace');
      }

      var start = (state.page - 1) * PAGE_SIZE;
      var visibleItems = items.slice(start, start + PAGE_SIZE);

      if (count) count.textContent = items.length + ' bài';
      if (inlineCount) inlineCount.textContent = items.length + ' bài';
      if (activeFilter) activeFilter.textContent = stateLabel();

      if (!items.length) {
        results.innerHTML = '' +
          '<div class="catalog-empty">' +
            '<h3>Không tìm thấy nội dung phù hợp</h3>' +
            '<p>Hãy đổi từ khóa hoặc bỏ bớt bộ lọc để xem nhiều bài viết hơn.</p>' +
          '</div>';
        renderPagination(0, 0);
        updateSeo(0, 0);
        return;
      }

	      results.innerHTML = '<div class="catalog-grid">' + visibleItems.map(function (article) {
	        var image = article.image || defaultFeatureImage();
	        var articleHref = buildArticleHref(article.href);
	        var badgeLabel = article.badge_label || article.library_kind_label || article.topic_lv1_label;
	        var topicLabel = article.topic_label || article.topic_lv2_label || article.topic_lv1_label;
	        var isNews = data.section === 'ban-tin';
	        var publishLabel = formatDateLabel(article.publish_date || article.publishDate || '');
	        var metaHtml = isNews && publishLabel
	          ? '<div class="catalog-card__meta"><span class="catalog-card__date"><i class="fa-regular fa-clock"></i>' + escapeHtml(publishLabel) + '</span></div>'
	          : '';
	        return '' +
	          '<article class="catalog-card' + (isNews ? ' catalog-card--news' : '') + '">' +
	            '<a class="catalog-card__media" data-article-link="1" href="' + escapeHtml(articleHref) + '">' +
	              '<img loading="lazy" decoding="async" src="' + escapeHtml(image) + '" alt="' + escapeHtml(article.title) + '">' +
	            '</a>' +
	            '<div class="catalog-card__body">' +
	              '<span class="catalog-card__badge">' + escapeHtml(badgeLabel) + '</span>' +
	              '<h3 class="catalog-card__title"><a data-article-link="1" href="' + escapeHtml(articleHref) + '">' + escapeHtml(article.title) + '</a></h3>' +
	              metaHtml +
	              '<p class="catalog-card__excerpt">' + escapeHtml(article.excerpt || '') + '</p>' +
	              '<div class="catalog-card__footer">' +
	                '<span class="catalog-card__topic">' + escapeHtml(topicLabel) + '</span>' +
	                '<a class="catalog-card__link" data-article-link="1" href="' + escapeHtml(articleHref) + '">Đọc bài <i class="fa-solid fa-angle-right"></i></a>' +
	              '</div>' +
	            '</div>' +
	          '</article>';
      }).join('') + '</div>';

      renderPagination(totalPages, items.length);
      updateSeo(totalPages, items.length);
    }

    function renderAll() {
      state = normalizeState(state, data);
      updateHeroContext();
      renderPrimaryFilters();
      renderAdvancedFilters();
      renderChips();
      renderResults();
    }

	    function resetFilters() {
	      state.q = '';
	      state.kind = '';
	      state.lv1 = '';
	      state.lv2 = '';
      state.page = 1;
      input.value = '';
      updateUrl(buildStateUrl(state), 'push');
      renderAll();
      closeFilterPanel();
    }

    input.addEventListener('input', function () {
      state.q = input.value.trim();
      state.page = 1;
      updateUrl(buildStateUrl(state), 'replace');
      renderChips();
      renderResults();
    });

    if (reset) {
      reset.addEventListener('click', resetFilters);
    }

    results.addEventListener('click', function (event) {
      var target = event.target && event.target.closest ? event.target.closest('a[data-article-link="1"]') : null;
      if (!target) return;
      saveReturnState();
    });

    if (toggleFilters) {
      toggleFilters.addEventListener('click', openFilterPanel);
    }
    if (closeFilters) {
      closeFilters.addEventListener('click', closeFilterPanel);
    }
    if (filterBackdrop) {
      filterBackdrop.addEventListener('click', closeFilterPanel);
    }

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') closeFilterPanel();
    });

    window.addEventListener('popstate', function () {
      state = normalizeState(readQueryState(), data);
	      if (!state.q && !state.kind && !state.lv1 && !state.lv2 && !hasExplicitPageParam()) {
	        state.page = Math.max(1, Number(data.currentPage || 1));
	      }
      input.value = state.q;
      renderAll();
    });

    renderAll();
    restoreScrollIfNeeded();
  }

  document.addEventListener('DOMContentLoaded', function () {
    loadHubData().then(initHubPage);
  });
})();
