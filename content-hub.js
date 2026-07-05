(function () {
  var PAGE_SIZE = 12;
  var CUSTOM_LIBRARY_KIND = {
    key: 'phan-loai-moi',
    label: 'Phân loại mới',
    count: 0,
    href: 'thu-vien.html?kind=phan-loai-moi',
    icon: 'fa-layer-group',
    description: 'Nhóm phân loại mới đang chuẩn bị nội dung'
  };

  function defaultFeatureImage() {
    return sitePath('assets/images/content/chia_se_kien_thuc_tai_lieu_KeToanDieuTam.jpg');
  }

  function getRootPrefix() {
    return (document.body && document.body.dataset && document.body.dataset.root) || '';
  }

  function sitePath(path) {
    if (!path) return '';
    if (/^(?:https?:)?\/\//.test(path) || path.indexOf('data:') === 0 || path.indexOf('mailto:') === 0 || path.indexOf('tel:') === 0) {
      return path;
    }
    if (path.indexOf('../') === 0 || path.indexOf('./') === 0 || path.indexOf('/') === 0) {
      return path;
    }
    return getRootPrefix() + path.replace(/^\.\//, '');
  }

  function ensureCustomLibraryKinds(data) {
    if (!data || data.section !== 'thu-vien') return data;
    var kinds = Array.isArray(data.libraryKinds) ? data.libraryKinds : [];
    var existing = kinds.find(function (item) {
      return item && item.key === CUSTOM_LIBRARY_KIND.key;
    });
    var customKind = Object.assign({}, CUSTOM_LIBRARY_KIND, existing || {});
    customKind.count = Number(customKind.count || 0);
    customKind.href = sitePath(customKind.href);
    data.libraryKinds = [customKind].concat(kinds.filter(function (item) {
      return !item || item.key !== CUSTOM_LIBRARY_KIND.key;
    }));
    data.taxonomyByKind = data.taxonomyByKind || {};
    if (!Array.isArray(data.taxonomyByKind[CUSTOM_LIBRARY_KIND.key])) {
      data.taxonomyByKind[CUSTOM_LIBRARY_KIND.key] = [];
    }
    return data;
  }

  function freshDataUrl(url) {
    var stamp = String(Date.now());
    return String(url || '') + (String(url || '').indexOf('?') === -1 ? '?' : '&') + 'v=' + encodeURIComponent(stamp);
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

  function loadScript(url) {
    return new Promise(function (resolve, reject) {
      var script = document.createElement('script');
      script.src = url;
      script.async = true;
      script.onload = function () { resolve(); };
      script.onerror = function () { reject(new Error('Không tải được script data: ' + url)); };
      document.head.appendChild(script);
    });
  }

  function loadHubData() {
    var inlineData = getHubData();
    if (!inlineData) return Promise.resolve(null);
    if (!inlineData.dataUrl) return Promise.resolve(inlineData);
    var mergeRemote = function (remote) {
      if (!remote) return inlineData;
      var merged = Object.assign({}, inlineData, remote);
      if (remote.sectionHref) merged.sectionHref = sitePath(remote.sectionHref);
      if (remote.sectionRootHref) merged.sectionRootHref = sitePath(remote.sectionRootHref);
      if (remote.pageMap) {
        merged.pageMap = {};
        Object.keys(remote.pageMap).forEach(function (key) {
          merged.pageMap[key] = sitePath(remote.pageMap[key]);
        });
      }
      if (remote.libraryKinds) {
        merged.libraryKinds = remote.libraryKinds.map(function (item) {
          return Object.assign({}, item, { href: sitePath(item.href) });
        });
      }
      if (remote.articles) {
        merged.articles = remote.articles.map(function (article) {
          return Object.assign({}, article, {
            href: sitePath(article.href),
            image: article.image ? sitePath(article.image) : article.image
          });
        });
      }
      return merged;
    };
    var loadFromScript = function () {
      var scriptUrl = freshDataUrl(inlineData.dataUrl.replace(/\.json$/, '.js'));
      return loadScript(scriptUrl).then(function () {
        var store = window.KetoanDieuTamHubStore || {};
        return mergeRemote(store[inlineData.section]);
      });
    };
    if (location.protocol === 'file:') {
      return loadFromScript().catch(function (error) {
        console.error('Hub data script fallback lỗi', error);
        return inlineData;
      });
    }
    return fetch(freshDataUrl(inlineData.dataUrl), { cache: 'no-store' })
      .then(function (response) {
        if (!response.ok) throw new Error('Không tải được hub data');
        return response.json();
      })
      .then(mergeRemote)
      .catch(function (error) {
        console.error('Hub data tải lỗi, chuyển sang script fallback', error);
        return loadFromScript().catch(function (fallbackError) {
          console.error('Hub data script fallback lỗi', fallbackError);
          return inlineData;
        });
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

  function normalizeSearchText(value) {
    return String(value || '')
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .replace(/đ/g, 'd')
      .replace(/[^a-z0-9]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function buildSearchBlob(article) {
    if (!article) return '';
    if (article.__search_blob) return article.__search_blob;
    article.__search_blob = normalizeSearchText([
      article.title,
      article.excerpt,
      article.badge_label,
      article.library_kind_label,
      article.library_kind_key,
      article.topic_label,
      article.topic_lv1_label,
      article.topic_lv1_key,
      article.topic_lv2_label,
      article.topic_lv2_key,
      article.topic_lv3_label,
      article.topic_lv3_key,
      article.href,
      (article.tags || []).join(' ')
    ].join(' '));
    return article.__search_blob;
  }

  function getSearchFields(article) {
    if (!article) return null;
    if (article.__search_fields) return article.__search_fields;
    article.__search_fields = {
      title: normalizeSearchText(article.title),
      excerpt: normalizeSearchText(article.excerpt),
      href: normalizeSearchText(article.href),
      badge: normalizeSearchText(article.badge_label),
      kind: normalizeSearchText(article.library_kind_label),
      topic: normalizeSearchText([
        article.topic_label,
        article.topic_lv1_label,
        article.topic_lv2_label,
        article.topic_lv3_label
      ].join(' ')),
      tags: normalizeSearchText((article.tags || []).join(' '))
    };
    return article.__search_fields;
  }

  function matchesSearch(article, query) {
    var normalizedQuery = normalizeSearchText(query);
    if (!normalizedQuery) return true;
    var haystack = buildSearchBlob(article);
    if (!haystack) return false;
    return normalizedQuery.split(' ').every(function (token) {
      return token && haystack.indexOf(token) !== -1;
    });
  }

  function getSearchScore(article, query) {
    var normalizedQuery = normalizeSearchText(query);
    if (!normalizedQuery) return 0;

    var fields = getSearchFields(article);
    if (!fields) return 0;

    var score = 0;
    if (fields.title === normalizedQuery) score += 5000;
    else if (fields.title.indexOf(normalizedQuery) !== -1) score += 2500;

    if (fields.href === normalizedQuery) score += 2200;
    else if (fields.href.indexOf(normalizedQuery) !== -1) score += 1200;

    if (fields.excerpt.indexOf(normalizedQuery) !== -1) score += 700;
    if (fields.topic.indexOf(normalizedQuery) !== -1) score += 500;
    if (fields.badge.indexOf(normalizedQuery) !== -1) score += 350;
    if (fields.kind.indexOf(normalizedQuery) !== -1) score += 300;
    if (fields.tags.indexOf(normalizedQuery) !== -1) score += 250;

    normalizedQuery.split(' ').forEach(function (token) {
      if (!token) return;
      if (fields.title.indexOf(token) !== -1) score += 80;
      if (fields.href.indexOf(token) !== -1) score += 60;
      if (fields.excerpt.indexOf(token) !== -1) score += 20;
      if (fields.topic.indexOf(token) !== -1) score += 15;
      if (fields.tags.indexOf(token) !== -1) score += 10;
      if (fields.badge.indexOf(token) !== -1) score += 10;
      if (fields.kind.indexOf(token) !== -1) score += 8;
    });

    return score;
  }

		  function readQueryState() {
		    var params = new URLSearchParams(location.search);
		    var page = parseInt(params.get('page') || '1', 10);
		    return {
		      q: params.get('q') || '',
		      kind: params.get('kind') || '',
		      badge: params.get('badge') || '',
		      tag: params.get('tag') || '',
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
		    if (state.badge) params.set('badge', state.badge);
		    if (state.tag) params.set('tag', state.tag);
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
          badge: state.badge || '',
		      lv1: state.lv1 || '',
		      tag: state.tag || '',
			      lv2: state.lv2 || '',
		      page: Math.max(1, Number(state.page || 1))
		    };

	    if (data.section !== 'thu-vien') {
	      next.kind = '';
	    } else if (next.kind) {
	      var validKind = (data.libraryKinds || []).some(function (item) { return item.key === next.kind; });
	      if (!validKind) next.kind = '';
	    } else if (next.lv1 || next.lv2) {
	      var kindMap = {};
	      (data.articles || []).forEach(function (article) {
	        if (!article.library_kind_key) return;
	        if (next.lv1 && article.topic_lv1_key !== next.lv1) return;
	        if (next.lv2 && article.topic_lv2_key !== next.lv2) return;
	        if (next.tag && !(article.tags || []).includes(next.tag)) return;
	        if (next.q && !matchesSearch(article, next.q)) return;
	        kindMap[article.library_kind_key] = true;
	      });
	      var inferredKinds = Object.keys(kindMap);
	      if (inferredKinds.length === 1) next.kind = inferredKinds[0];
	    }

    var scopedTaxonomy = data.taxonomy || [];
    if (data.section === 'thu-vien' && next.kind) {
      scopedTaxonomy = taxonomyForLibraryKind(data, next.kind);
    }

	    var lv1 = scopedTaxonomy.find(function (item) { return item.key === next.lv1; });
	    if (!lv1) {
	      if (next.lv2) {
	        for (var i = 0; i < scopedTaxonomy.length; i += 1) {
	          var hit = (scopedTaxonomy[i].children || []).find(function (item) { return item.key === next.lv2; });
	          if (hit) {
	            next.lv1 = scopedTaxonomy[i].key;
	            return next;
	          }
	        }
	      }
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

  function taxonomyForLibraryKind(data, kind) {
    if (data && data.taxonomyByKind && Array.isArray(data.taxonomyByKind[kind])) {
      return data.taxonomyByKind[kind];
    }
    return buildTaxonomyFromArticles(((data && data.articles) || []).filter(function (article) {
      return article.library_kind_key === kind;
    }));
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
    var heroContext = document.getElementById('hubHeroContext');
    var secondaryFilters = document.getElementById('hubSecondaryFilters');
    var primaryFiltersToggle = document.getElementById('hubPrimaryFiltersToggle');
    var desktopTree = document.getElementById('hubDesktopTree');
    var toggleFilters = document.getElementById('hubToggleFilters');
    var closeFilters = document.getElementById('hubCloseFilters');
    var filterPanel = document.getElementById('hubFilterPanel');
    var filterBackdrop = document.getElementById('hubFilterBackdrop');
    var pagination = document.getElementById('hubPagination');
    var filterHint = document.querySelector('.catalog-filter-panel__hint');

    if (!input || !results) return;

	    var state = normalizeState(readQueryState(), data);
		    if (!state.q && !state.kind && !state.badge && !state.lv1 && !state.lv2 && !hasExplicitPageParam()) {
		      state.page = Math.max(1, Number(data.currentPage || 1));
		    }
    input.value = state.q;
    var baseTitle = document.title;
    var baseDescriptionMeta = document.querySelector('meta[name="description"]');
    var baseDescription = baseDescriptionMeta ? (baseDescriptionMeta.getAttribute('content') || '') : '';
	    var heroKicker = document.querySelector('.catalog-kicker');
	    var heroTitle = document.querySelector('.catalog-title');
	    var heroDescription = document.querySelector('.catalog-description');
      var heroSection = document.querySelector('.catalog-hero');
	    var baseHero = {
	      kickerHtml: heroKicker ? heroKicker.innerHTML : '',
	      title: heroTitle ? heroTitle.textContent : '',
	      description: heroDescription ? heroDescription.textContent : '',
	      searchPlaceholder: input.getAttribute('placeholder') || ''
	    };

	    function ensureCatalogBreadcrumb() {
	      if (!heroSection) return;
	      var heroContainer = heroSection.querySelector('.container');
	      if (!heroContainer) return;
	      if (heroContainer.querySelector('.catalog-breadcrumbs')) return;
	      var root = (document.body && document.body.dataset.root) || '';
	      var currentLabel = baseHero.title || (data.section === 'thu-vien' ? 'Thư viện' : 'Bản tin');
	      var breadcrumb = document.createElement('nav');
	      breadcrumb.className = 'catalog-breadcrumbs';
	      breadcrumb.setAttribute('aria-label', 'Breadcrumb');
	      breadcrumb.innerHTML =
	        '<a href="' + escapeHtml(root + 'index.html') + '">Trang chủ</a>' +
	        '<i class="fa-solid fa-angle-right" aria-hidden="true"></i>' +
	        '<span>' + escapeHtml(currentLabel) + '</span>';
	      heroContainer.insertBefore(breadcrumb, heroContainer.firstChild);
	    }

	    ensureCatalogBreadcrumb();

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
      var activeKind = getLibraryKind(state.kind);
      if (activeKind) {
        return {
          kickerHtml: '<i class="fa-solid ' + escapeHtml(activeKind.icon || 'fa-layer-group') + '"></i> ' + escapeHtml(activeKind.label),
          title: activeKind.label,
          description: activeKind.description || baseHero.description,
          searchPlaceholder: 'Tìm trong ' + String(activeKind.label || 'phân loại').toLowerCase() + '...'
        };
      }
      return baseHero;
    }

    function updateHeroContext() {
      var hero = getHeroConfig();
      if (heroSection && heroSection.classList) {
        heroSection.classList.toggle('catalog-hero--focused', Boolean(state.kind || state.lv1 || state.lv2));
        heroSection.classList.toggle('catalog-hero--deep', Boolean(state.lv1 || state.lv2));
      }
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
      return taxonomyForLibraryKind(data, state.kind);
    }

    function getLibraryLevel2Options() {
      if (!(data.section === 'thu-vien' && state.kind)) return [];
      var scopedTaxonomy = getScopedTaxonomy();
      return scopedTaxonomy.map(function (lv1) {
        return { mode: 'lv1', key: lv1.key, label: lv1.label, count: lv1.count };
      });
    }

    function getLibraryLevel3Options() {
      if (!(data.section === 'thu-vien' && state.kind && state.lv1)) return [];
      var activeLv1 = getLv1(state.lv1);
      if (!activeLv1) return [];
      return (activeLv1.children || []).map(function (lv2) {
        return { mode: 'lv2', key: lv2.key, label: lv2.label, count: lv2.count };
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

	    function deriveToolSubgroup(article) {
	      var title = String((article && article.title) || '').toLowerCase();
	      var tagList = (article && article.tags ? article.tags : []);
	      var tags = tagList.join(' ').toLowerCase();
	      var text = title + ' ' + tags;
	      var topicLv2 = String((article && article.topic_lv2_key) || '').toLowerCase();

	      if (topicLv2 === 'htkk-etax-thue-dien-tu') {
	        if (tagList.indexOf('Quyết toán') !== -1) return 'Quyết toán';
	        if (tagList.indexOf('Kê khai') !== -1) return 'Kê khai';
	        if (/đăng ký|dang ky|mst|mã số thuế|ma so thue/.test(text)) return 'Đăng ký thuế';
	        if (/hoàn thuế|hoan thue/.test(text)) return 'Hoàn thuế';
	        if (/quyết toán|quyet toan/.test(text)) return 'Quyết toán';
		        if (/nộp tờ khai|nop to khai|nộp thuế điện tử|nop thue dien tu|gửi tờ khai|gui to khai/.test(text)) return 'Nộp tờ khai';
		        if (/cài đặt|cai dat/.test(text)) return 'Cài đặt';
		        if (/nâng cấp|nang cap|phiên bản|phien ban|mới nhất|moi nhat/.test(text)) return 'Nâng cấp';
		        if (/tải về|tai ve|dùng thử|dung thu|phần mềm|phan mem/.test(text)) return 'Tải về';
		        if (/kê khai|ke khai|tờ khai|to khai/.test(text)) return 'Kê khai';
		        if (/\b(htkk|etax)\b|\b\d+\.\d+\.\d+\b/.test(text)) return 'Nâng cấp';
		        return '';
		      }

	      if (topicLv2 === 'excel-va-cong-cu-khac') {
	        if (tagList.indexOf('Biểu mẫu') !== -1) return 'Mẫu file';
	        if (tagList.indexOf('BCTC') !== -1) return 'Báo cáo';
	        if (tagList.indexOf('Tiền lương') !== -1) return 'Tiền lương';
	        if (tagList.indexOf('TSCĐ') !== -1 || tagList.indexOf('CCDC') !== -1 || tagList.indexOf('Khấu hao') !== -1) return 'TSCĐ / CCDC';
	        if (tagList.indexOf('GTGT') !== -1 || tagList.indexOf('TNCN') !== -1 || tagList.indexOf('TNDN') !== -1 || tagList.indexOf('Thuế') !== -1 || tagList.indexOf('HTKK') !== -1 || tagList.indexOf('Người phụ thuộc') !== -1 || tagList.indexOf('Nhà thầu') !== -1) return 'Thuế';
	        if (tagList.indexOf('Hướng dẫn') !== -1) return 'Thực hành';
	        if (/^mẫu| mẫu |trọn bộ mẫu|file excel|trên excel/.test(text)) return 'Mẫu file';
	        if (/hàm|ham|vlookup|sumif|subtotal/.test(text)) return 'Hàm Excel';
	        if (/tiền lương|tien luong|lương/.test(text)) return 'Tiền lương';
	        if (/gtgt|tncn|tndn|thuế|thue|người phụ thuộc|nguoi phu thuoc/.test(text)) return 'Thuế';
		        if (/tscđ|tscd|khấu hao|khau hao|ccdc/.test(text)) return 'TSCĐ / CCDC';
		        if (/bctc|báo cáo tài chính|bao cao tai chinh/.test(text)) return 'Báo cáo';
	        if (/phím tắt|phim tat|thay thế|thay the|đối chiếu|doi chieu|cách|huớng dẫn|hướng dẫn|huong dan|khóa học|khoa hoc|sử dụng|su dung/.test(text)) return 'Thực hành';
	        return '';
	      }

	      if (topicLv2 === 'fast') {
	        if (tagList.indexOf('Cài đặt') !== -1) return 'Cài đặt';
	        if (tagList.indexOf('Tải về') !== -1) return 'Tải về';
	        if (tagList.indexOf('Hướng dẫn') !== -1) return 'Sử dụng';
	        if (/cài đặt|cai dat/.test(text)) return 'Cài đặt';
	        if (/tải về|tai ve|dùng thử|dung thu/.test(text)) return 'Tải về';
	        if (/sử dụng|su dung|hướng dẫn|huong dan/.test(text)) return 'Sử dụng';
	        return '';
	      }

	      return '';
	    }

		    function stateLabel() {
			      if (state.tag) {
              if (state.lv2) {
                var activeLv2 = getLv2(state.lv1, state.lv2);
                return activeLv2 ? (activeLv2.label + ' · ' + state.tag) : state.tag;
              }
			        return state.tag;
			      }
			      if (state.badge) {
		        return state.badge;
		      }
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
		      var needle = state.q.trim();
				      var items = data.articles.filter(function (article) {
			        if (state.kind && article.library_kind_key !== state.kind) return false;
			        if (state.badge && (article.badge_label || '') !== state.badge) return false;
			        if (state.tag && !(article.tags || []).includes(state.tag)) return false;
			        if (state.lv1 && article.topic_lv1_key !== state.lv1) return false;
	        if (state.lv2 && article.topic_lv2_key !== state.lv2) return false;
	        return matchesSearch(article, needle);
		      });
          if (needle) {
            items.sort(function (a, b) {
              return getSearchScore(b, needle) - getSearchScore(a, needle);
            });
          }
          return items;
		    }

		    function getScopeArticles(ignoreTextAndTag) {
		      var needle = state.q.trim();
		      return data.articles.filter(function (article) {
		        if (state.kind && article.library_kind_key !== state.kind) return false;
		        if (state.badge && (article.badge_label || '') !== state.badge) return false;
		        if (state.lv1 && article.topic_lv1_key !== state.lv1) return false;
		        if (state.lv2 && article.topic_lv2_key !== state.lv2) return false;
		        if (ignoreTextAndTag) return true;
		        if (state.tag && !(article.tags || []).includes(state.tag)) return false;
		        return matchesSearch(article, needle);
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
      return appendQueryParam(sitePath(articleHref), 'from', currentReturnUrl());
    }

		    function hasFilters() {
		      return Boolean(state.q || state.kind || state.badge || state.tag || state.lv1 || state.lv2);
		    }

	    function buildStateUrl(nextState) {
	      var filtered = Boolean(nextState.q || nextState.kind || nextState.badge || nextState.tag || nextState.lv1 || nextState.lv2);
	      if (!filtered) {
	        var pageMap = data.pageMap || {};
	        var direct = pageMap[String(nextState.page)];
	        if (direct) return direct;
	        var rootHref = pageMap['1'] || data.sectionRootHref || location.pathname;
	        if (nextState.page > 1) {
	          return appendQueryParam(rootHref, 'page', String(nextState.page));
	        }
	        return rootHref;
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
	      if (state.badge) {
	        htmlParts.push('<span class="catalog-chip"><i class="fa-solid fa-bookmark"></i>' + escapeHtml(state.badge) + '</span>');
	      }
	      if (state.tag) {
	        htmlParts.push('<span class="catalog-chip"><i class="fa-solid fa-hashtag"></i>' + escapeHtml(state.tag) + '</span>');
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
        if (heroContext) {
          heroContext.innerHTML = '';
          heroContext.style.display = 'none';
        }
        if (secondaryFilters) {
          secondaryFilters.innerHTML = '';
          secondaryFilters.style.display = 'none';
        }
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
              if (heroContext && activeKind) {
                heroContext.innerHTML =
                  '<button class="catalog-hero-back" type="button" data-kind-reset="1">' +
                    '<i class="fa-solid fa-arrow-left"></i><span>Thư viện</span>' +
                  '</button>';
                heroContext.style.display = 'flex';
              }
            var level2Items = getLibraryLevel2Options();
	          level2Items.forEach(function (item) {
	            var active = state.lv1 === item.key;
	            htmlParts.push(
	              '<button class="catalog-primary-btn' + (active ? ' is-active' : '') + '" type="button" data-scope-mode="' + item.mode + '" data-scope-key="' + escapeHtml(item.key) + '">' +
	                '<span>' + escapeHtml(item.label) + '</span><small>' + item.count + '</small>' +
	              '</button>'
	            );
	          });

            if (secondaryFilters) {
              var level3Items = getLibraryLevel3Options();
              if (level3Items.length) {
                secondaryFilters.innerHTML = level3Items.map(function (item) {
                  return '<button class="catalog-secondary-btn' + (state.lv2 === item.key ? ' is-active' : '') + '" type="button" data-scope-mode="lv2" data-scope-key="' + escapeHtml(item.key) + '">' +
                    '<span>' + escapeHtml(item.label) + '</span><small>' + item.count + '</small>' +
                  '</button>';
                }).join('');
                secondaryFilters.style.display = 'flex';
              }
            }
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
          if (secondaryFilters && state.lv1) {
            var activeNewsLv1 = getLv1(state.lv1);
            var newsLevel3 = activeNewsLv1 && activeNewsLv1.children ? activeNewsLv1.children : [];
            if (newsLevel3.length) {
              secondaryFilters.innerHTML = newsLevel3.map(function (item) {
                return '<button class="catalog-secondary-btn' + (state.lv2 === item.key ? ' is-active' : '') + '" type="button" data-lv1="' + escapeHtml(state.lv1) + '" data-lv2="' + escapeHtml(item.key) + '">' +
                  '<span>' + escapeHtml(item.label) + '</span><small>' + item.count + '</small>' +
                '</button>';
              }).join('');
              secondaryFilters.style.display = 'flex';
            }
          }
	      }

	      primaryFilters.innerHTML = htmlParts.join('');
	      primaryFilters.classList.remove('is-collapsed');

	      if (!primaryFiltersToggle && primaryFilters.parentNode) {
	        primaryFiltersToggle = document.createElement('button');
	        primaryFiltersToggle.id = 'hubPrimaryFiltersToggle';
	        primaryFiltersToggle.className = 'catalog-primary-toggle-more';
	        primaryFiltersToggle.type = 'button';
	        primaryFilters.parentNode.insertBefore(primaryFiltersToggle, primaryFilters.nextSibling);
	      }

	      if (primaryFiltersToggle) {
	        primaryFiltersToggle.hidden = true;
	        primaryFiltersToggle.textContent = 'Xem thêm';
	        primaryFiltersToggle.setAttribute('aria-expanded', 'false');
	        primaryFiltersToggle.onclick = null;
	      }

	      function bindScopeButton(button) {
	        button.addEventListener('click', function () {
          var isActive = button.classList.contains('is-active');
		          if (button.dataset.kindReset) {
		            state.kind = '';
		            state.badge = '';
		            state.tag = '';
			            state.lv1 = '';
			            state.lv2 = '';
		          } else if (button.dataset.lv2) {
		            state.badge = '';
		            state.tag = '';
		            state.lv1 = button.dataset.lv1 || state.lv1 || '';
		            state.lv2 = isActive ? '' : (button.dataset.lv2 || '');
		          } else if (button.dataset.scopeMode === 'lv1') {
		            state.badge = '';
		            state.tag = '';
		            state.lv1 = isActive ? '' : (button.dataset.scopeKey || '');
		            state.lv2 = '';
		          } else if (button.dataset.scopeMode === 'lv2') {
		            state.badge = '';
		            state.tag = '';
		            state.lv2 = isActive ? '' : (button.dataset.scopeKey || '');
		          } else if (button.dataset.kind !== undefined) {
		            state.kind = isActive ? '' : (button.dataset.kind || '');
		            state.badge = '';
		            state.tag = '';
		            state.lv1 = '';
		            state.lv2 = '';
		          } else {
		            state.badge = '';
		            state.tag = '';
		            state.lv1 = isActive ? '' : (button.dataset.lv1 || '');
		            state.lv2 = '';
		          }
	          state.page = 1;
	          updateUrl(buildStateUrl(state), 'push');
	          renderAll();
	        });
        }
	      if (heroContext) {
          heroContext.querySelectorAll('button').forEach(bindScopeButton);
        }
	      primaryFilters.querySelectorAll('.catalog-primary-btn').forEach(bindScopeButton);
        if (secondaryFilters) {
          secondaryFilters.querySelectorAll('.catalog-secondary-btn').forEach(bindScopeButton);
        }

	      if (primaryFiltersToggle && data.section === 'thu-vien' && !state.kind) {
	        primaryFiltersToggle.hidden = true;
        return;
      }

      window.requestAnimationFrame(function () {
        if (!primaryFilters || !primaryFiltersToggle) return;
        primaryFilters.querySelectorAll('.catalog-primary-btn').forEach(function (button) {
          button.classList.remove('is-overflow-item');
        });
        if (window.innerWidth <= 767) {
          primaryFilters.classList.remove('is-collapsed');
          primaryFiltersToggle.hidden = true;
          return;
        }
        var rowTops = [];
        primaryFilters.querySelectorAll('.catalog-primary-btn').forEach(function (button) {
          if (rowTops.indexOf(button.offsetTop) === -1) rowTops.push(button.offsetTop);
        });
        rowTops.sort(function (a, b) { return a - b; });
        var cutoffTop = rowTops.length > 2 ? rowTops[1] : null;
        if (cutoffTop !== null) {
          primaryFilters.querySelectorAll('.catalog-primary-btn').forEach(function (button) {
            if (button.offsetTop > cutoffTop) {
              button.classList.add('is-overflow-item');
            }
          });
        }
        var needsClamp = rowTops.length > 2;
        if (!needsClamp) {
          primaryFilters.classList.remove('is-collapsed');
          primaryFiltersToggle.hidden = true;
          return;
        }
        primaryFilters.classList.add('is-collapsed');
        primaryFiltersToggle.hidden = false;
        primaryFiltersToggle.textContent = primaryFilters.classList.contains('is-collapsed') ? 'Xem thêm' : 'Thu gọn';
        primaryFiltersToggle.setAttribute('aria-expanded', primaryFilters.classList.contains('is-collapsed') ? 'false' : 'true');
        primaryFiltersToggle.onclick = function () {
          primaryFilters.classList.toggle('is-collapsed');
          primaryFiltersToggle.textContent = primaryFilters.classList.contains('is-collapsed') ? 'Xem thêm' : 'Thu gọn';
          primaryFiltersToggle.setAttribute('aria-expanded', primaryFilters.classList.contains('is-collapsed') ? 'false' : 'true');
        };
      });
	    }

	    function renderDesktopTree() {
	      if (!desktopTree) return;
      var sections = [];
      if (data.section === 'thu-vien') {
        (data.libraryKinds || []).forEach(function (kind) {
          var taxonomy = taxonomyForLibraryKind(data, kind.key);
          var rootActive = state.kind === kind.key;
          var groups = taxonomy.map(function (lv1) {
            var groupActive = rootActive && state.lv1 === lv1.key;
            var children = (lv1.children || []).map(function (lv2) {
              var childActive = state.kind === kind.key && state.lv2 === lv2.key;
              return '' +
                '<li>' +
                  '<button class="catalog-tree__child' + (childActive ? ' is-active' : '') + '" type="button" data-kind="' + escapeHtml(kind.key) + '" data-lv1="' + escapeHtml(lv1.key) + '" data-lv2="' + escapeHtml(lv2.key) + '">' +
                    '<span>' + escapeHtml(lv2.label) + '</span><small>' + lv2.count + '</small>' +
                  '</button>' +
                '</li>';
            }).join('');
            return '' +
              '<li>' +
                '<button class="catalog-tree__group' + (groupActive && !state.lv2 ? ' is-active' : '') + '" type="button" data-kind="' + escapeHtml(kind.key) + '" data-lv1="' + escapeHtml(lv1.key) + '">' +
                  '<span>' + escapeHtml(lv1.label) + '</span><small>' + lv1.count + '</small>' +
                '</button>' +
                (groupActive && children ? '<ul class="catalog-tree__children">' + children + '</ul>' : '') +
              '</li>';
          }).join('');
          sections.push(
            '<section class="catalog-tree__section' + (rootActive ? ' is-active' : '') + '">' +
              '<button class="catalog-tree__root' + (rootActive ? ' is-active' : '') + '" type="button" data-kind="' + escapeHtml(kind.key) + '">' +
                '<span>' + escapeHtml(kind.label) + '</span><small>' + kind.count + '</small>' +
              '</button>' +
              (rootActive ? '<ul class="catalog-tree__groups">' + groups + '</ul>' : '') +
            '</section>'
          );
        });
      } else {
        sections = (data.taxonomy || []).map(function (lv1) {
          var rootActive = state.lv1 === lv1.key;
          var children = (lv1.children || []).map(function (lv2) {
            var childActive = state.lv2 === lv2.key;
            return '' +
              '<li>' +
                '<button class="catalog-tree__child' + (childActive ? ' is-active' : '') + '" type="button" data-lv1="' + escapeHtml(lv1.key) + '" data-lv2="' + escapeHtml(lv2.key) + '">' +
                  '<span>' + escapeHtml(lv2.label) + '</span><small>' + lv2.count + '</small>' +
                '</button>' +
              '</li>';
          }).join('');
          return '' +
            '<section class="catalog-tree__section' + (rootActive ? ' is-active' : '') + '">' +
              '<button class="catalog-tree__root' + (rootActive && !state.lv2 ? ' is-active' : '') + '" type="button" data-lv1="' + escapeHtml(lv1.key) + '">' +
                '<span>' + escapeHtml(lv1.label) + '</span><small>' + lv1.count + '</small>' +
              '</button>' +
              (rootActive ? '<ul class="catalog-tree__children">' + children + '</ul>' : '') +
            '</section>';
        });
      }

      desktopTree.innerHTML =
        '<h3 class="catalog-sidebar__title">' + (data.section === 'thu-vien' ? 'Thư viện' : 'Bản tin') + '</h3>' +
        '<div class="catalog-tree">' + sections.join('') + '</div>';

	      desktopTree.querySelectorAll('button').forEach(function (button) {
	        button.addEventListener('click', function () {
	          var isActive = button.classList.contains('is-active');
          state.badge = '';
          state.tag = '';
          if (button.dataset.kind !== undefined) {
            state.kind = isActive ? '' : (button.dataset.kind || '');
          }
          state.lv1 = isActive ? '' : (button.dataset.lv1 || '');
          state.lv2 = isActive ? '' : (button.dataset.lv2 || '');
          state.page = 1;
          updateUrl(buildStateUrl(state), 'push');
	          renderAll();
	        });
	      });

	      if (window.requestAnimationFrame && window.innerWidth > 991 && (state.kind || state.lv1 || state.lv2)) {
	        window.requestAnimationFrame(function () {
	          if (!desktopTree || desktopTree.offsetParent === null) return;
	          var activeNode = desktopTree.querySelector('.catalog-tree__child.is-active, .catalog-tree__group.is-active, .catalog-tree__root.is-active');
	          if (!activeNode || !activeNode.scrollIntoView) return;
	          activeNode.scrollIntoView({
	            behavior: 'smooth',
	            block: 'nearest',
	            inline: 'nearest'
	          });
	        });
	      }
	    }

	    function renderAdvancedFilters() {
	      if (!filters) return;
        if (filterHint) {
          filterHint.textContent = 'Chọn chuyên đề để thu hẹp kết quả.';
        }

	      if (data.section === 'thu-vien' && state.kind && state.kind !== 'huong-dan' && state.lv2) {
	        var currentScopeArticles = getScopeArticles(true);
	        var activeLv2 = getLv2(state.lv1, state.lv2);
          if (filterHint && activeLv2) {
            filterHint.textContent = 'Lọc sâu trong ' + activeLv2.label + ' bằng các nhãn con phù hợp.';
          }
	        var tagItems = [];
	        if (state.kind === 'cong-cu') {
	          var subgroupCounts = {};
	          currentScopeArticles.forEach(function (article) {
	            var subgroup = article.tool_lv3_label || deriveToolSubgroup(article);
	            if (!subgroup) return;
	            subgroupCounts[subgroup] = (subgroupCounts[subgroup] || 0) + 1;
	          });
	          tagItems = Object.keys(subgroupCounts).map(function (key) {
	            return { key: key, count: subgroupCounts[key] };
	          }).filter(function (item) {
	            return item.count >= 1;
	          }).sort(function (a, b) {
	            return b.count - a.count || a.key.localeCompare(b.key, 'vi');
	          }).slice(0, 12);
	        } else {
	          var tagCounts = {};
	          var ignore = {};
	          [
	            activeLv2 && activeLv2.label,
	            state.tag,
	            'Thư viện',
	            'Biểu mẫu',
	            'Mẫu biểu',
	            'Thủ tục',
	            'Công cụ',
	            'Công cụ khác',
	            'Phần mềm',
	            'Hướng dẫn',
	            'HTKK',
	            'eTax',
	            'Thuế điện tử',
	            'Excel'
	          ].forEach(function (value) {
	            if (value) ignore[String(value).toLowerCase()] = true;
	          });
	          currentScopeArticles.forEach(function (article) {
	            (article.tags || []).forEach(function (tag) {
	              var key = String(tag || '').trim();
	              if (!key) return;
	              if (ignore[key.toLowerCase()]) return;
	              tagCounts[key] = (tagCounts[key] || 0) + 1;
	            });
	          });
	          tagItems = Object.keys(tagCounts).map(function (key) {
	            return { key: key, count: tagCounts[key] };
	          }).filter(function (item) {
	            return item.count >= 2;
	          }).sort(function (a, b) {
	            return b.count - a.count || a.key.localeCompare(b.key, 'vi');
	          }).slice(0, 18);
	        }
	        filters.innerHTML = '' +
	          '<section class="catalog-filter-group">' +
	            '<h4>' + escapeHtml(activeLv2 ? activeLv2.label : stateLabel()) + ' <small>' + currentScopeArticles.length + ' bài</small></h4>' +
	            '<div class="catalog-filter-list">' +
	              '<button class="catalog-filter-btn' + (!state.tag ? ' is-active' : '') + '" type="button" data-tag="">' +
	                '<span>Tất cả</span><small>' + currentScopeArticles.length + '</small>' +
	              '</button>' +
	              tagItems.map(function (item) {
	                var active = state.tag === item.key ? ' is-active' : '';
	                return '' +
	                  '<button class="catalog-filter-btn' + active + '" type="button" data-tag="' + escapeHtml(item.key) + '">' +
	                    '<span>' + escapeHtml(item.key) + '</span><small>' + item.count + '</small>' +
	                  '</button>';
	              }).join('') +
	            '</div>' +
	          '</section>';
	        filters.querySelectorAll('.catalog-filter-btn').forEach(function (button) {
	          button.addEventListener('click', function () {
	            state.tag = button.dataset.tag || '';
	            state.page = 1;
	            updateUrl(buildStateUrl(state), 'push');
	            renderAll();
	            closeFilterPanel();
	          });
	        });
	        return;
	      }

	      var scopedTaxonomy = getScopedTaxonomy();
        if (filterHint && state.kind && state.kind === 'huong-dan' && state.lv1) {
          var activeLv1 = getLv1(state.lv1);
          if (activeLv1) {
            filterHint.textContent = 'Chọn nhóm con trong ' + activeLv1.label + ' để thu hẹp kết quả.';
          }
        } else if (filterHint && data.section === 'ban-tin' && state.lv1) {
          var newsLv1 = getLv1(state.lv1);
          if (newsLv1) {
            filterHint.textContent = 'Chọn nhóm con trong ' + newsLv1.label + ' để thu hẹp kết quả.';
          }
        }
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
	          state.badge = '';
	          state.tag = '';
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
	        badge: state.badge,
	        tag: state.tag,
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

    function isInitialStaticPageState() {
      return !hasFilters() && state.page === Math.max(1, Number(data.currentPage || 1));
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

      if (isInitialStaticPageState() && results && results.innerHTML.trim()) {
        updateSeo(totalPages, items.length);
        return;
      }

		      results.innerHTML = '<div class="catalog-grid">' + visibleItems.map(function (article) {
		        var image = article.image || defaultFeatureImage();
		        var articleHref = buildArticleHref(article.href);
		        var badgeLabel = article.badge_label || article.library_kind_label || article.topic_lv1_label;
		        var topicLabel = article.topic_label || article.topic_lv2_label || article.topic_lv1_label;
		        var isNews = data.section === 'ban-tin';
		        var showBadge = !(data.section === 'thu-vien' && state.kind);
		        if (isNews && state.lv2 && badgeLabel === topicLabel) {
		          showBadge = false;
		        }
		        if (state.badge && badgeLabel === state.badge) {
		          showBadge = false;
		        }
		        var showTopic = true;
		        if (topicLabel === badgeLabel) {
		          showTopic = false;
		        }
		        if (state.lv2 && article.topic_lv2_key && state.lv2 === article.topic_lv2_key) {
		          showTopic = false;
		        }
		        if (showTopic && state.lv1 && !state.lv2 && article.topic_lv1_key && state.lv1 === article.topic_lv1_key && !article.topic_lv2_key) {
		          showTopic = false;
		        }
		        var publishLabel = formatDateLabel(article.publish_date || article.publishDate || '');
		        var metaHtml = isNews && publishLabel
		          ? '<div class="catalog-card__meta"><span class="catalog-card__date"><i class="fa-regular fa-clock"></i>' + escapeHtml(publishLabel) + '</span></div>'
		          : '';
		        var badgeHtml = showBadge
		          ? '<button class="catalog-card__badge" type="button" data-card-badge="' + escapeHtml(badgeLabel) + '"' +
		              (data.section === 'thu-vien' && article.library_kind_key ? ' data-card-kind="' + escapeHtml(article.library_kind_key) + '"' : '') + '>' +
		              escapeHtml(badgeLabel) +
		            '</button>'
		          : '';
			        var topicHtml = showTopic
			          ? '<button class="catalog-card__topic" type="button"' +
			              (data.section === 'thu-vien' && article.library_kind_key ? ' data-card-kind="' + escapeHtml(article.library_kind_key) + '"' : '') +
			              (article.topic_lv2_key ? ' data-card-lv2="' + escapeHtml(article.topic_lv2_key) + '"' : '') +
			              (article.topic_lv1_key ? ' data-card-lv1="' + escapeHtml(article.topic_lv1_key) + '"' : '') +
			            '>' + escapeHtml(topicLabel) + '</button>'
			          : '';
			        return '' +
			          '<article class="catalog-card' + (isNews ? ' catalog-card--news' : '') + '">' +
			            '<a class="catalog-card__media" data-article-link="1" href="' + escapeHtml(articleHref) + '">' +
			              '<img loading="lazy" decoding="async" src="' + escapeHtml(image) + '" alt="' + escapeHtml(article.title) + '">' +
			            '</a>' +
			            '<div class="catalog-card__body">' +
			              badgeHtml +
			              '<h3 class="catalog-card__title"><a data-article-link="1" href="' + escapeHtml(articleHref) + '">' + escapeHtml(article.title) + '</a></h3>' +
			              metaHtml +
			              '<p class="catalog-card__excerpt">' + escapeHtml(article.excerpt || '') + '</p>' +
			              '<div class="catalog-card__footer' + (!showTopic ? ' is-link-only' : '') + '">' +
				                topicHtml +
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
      renderDesktopTree();
      renderPrimaryFilters();
      renderAdvancedFilters();
      renderChips();
      renderResults();
    }

			    function resetFilters() {
			      state.q = '';
			      state.kind = '';
			      state.badge = '';
			      state.tag = '';
			      state.lv1 = '';
		      state.lv2 = '';
      state.page = 1;
      input.value = '';
      updateUrl(buildStateUrl(state), 'push');
      renderAll();
      closeFilterPanel();
    }

			    input.addEventListener('input', function () {
			      state.badge = '';
			      state.tag = '';
			      state.q = input.value.replace(/\s+/g, ' ').trim();
	      state.page = 1;
	      updateUrl(buildStateUrl(state), 'replace');
	      renderAll();
	    });

    if (reset) {
      reset.addEventListener('click', resetFilters);
    }

		    results.addEventListener('click', function (event) {
		      var badgeFilter = event.target && event.target.closest ? event.target.closest('[data-card-badge]') : null;
			      if (badgeFilter) {
            event.preventDefault();
            var sameBadgeKind = badgeFilter.dataset.cardKind && state.kind === badgeFilter.dataset.cardKind && !state.lv1 && !state.lv2 && !state.tag;
            var sameBadge = !badgeFilter.dataset.cardKind && state.badge === (badgeFilter.dataset.cardBadge || '') && !state.lv1 && !state.lv2 && !state.tag;
			        state.page = 1;
			        if (badgeFilter.dataset.cardKind) {
			          state.kind = sameBadgeKind ? '' : badgeFilter.dataset.cardKind;
			          state.badge = '';
			        } else {
			          state.badge = sameBadge ? '' : (badgeFilter.dataset.cardBadge || '');
			          state.kind = '';
			        }
			        state.tag = '';
			        state.lv1 = '';
		        state.lv2 = '';
	        updateUrl(buildStateUrl(state), 'push');
	        renderAll();
	        return;
		      }
		      var topicFilter = event.target && event.target.closest ? event.target.closest('[data-card-lv1], [data-card-lv2]') : null;
			      if (topicFilter) {
            event.preventDefault();
	            var nextKind = topicFilter.dataset.cardKind || '';
	            if (!nextKind && data.section === 'thu-vien' && topicFilter.closest) {
	              var card = topicFilter.closest('.catalog-card');
	              var badge = card && card.querySelector ? card.querySelector('[data-card-kind]') : null;
	              if (badge && badge.dataset) nextKind = badge.dataset.cardKind || '';
	            }
            var nextLv1 = topicFilter.dataset.cardLv1 || '';
            var nextLv2 = topicFilter.dataset.cardLv2 || '';
	            var sameTopic = state.lv1 === nextLv1 && state.lv2 === nextLv2 && (!nextKind || state.kind === nextKind) && !state.tag;
			        state.page = 1;
			        state.badge = '';
			        state.tag = '';
			        if (data.section === 'thu-vien') {
			          state.kind = sameTopic ? '' : nextKind;
			        }
			        state.lv1 = sameTopic ? '' : nextLv1;
		        state.lv2 = sameTopic ? '' : nextLv2;
		        updateUrl(buildStateUrl(state), 'push');
		        renderAll();
		        return;
		      }
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
				      if (!state.q && !state.kind && !state.badge && !state.tag && !state.lv1 && !state.lv2 && !hasExplicitPageParam()) {
				        state.page = Math.max(1, Number(data.currentPage || 1));
				      }
      input.value = state.q;
      renderAll();
    });

    renderAll();
    restoreScrollIfNeeded();
  }

  document.addEventListener('DOMContentLoaded', function () {
    loadHubData().then(ensureCustomLibraryKinds).then(initHubPage);
  });
})();
