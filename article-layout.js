(function () {
  if (window.KetoanDieuTamArticleLayoutLoaded) return;
  window.KetoanDieuTamArticleLayoutLoaded = true;

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function (char) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char];
    });
  }

  function rootPath() {
    return (document.body && document.body.dataset && document.body.dataset.root) || '';
  }

  function sitePath(href) {
    if (!href) return '#';
    if (/^(https?:|mailto:|tel:|#)/i.test(href)) return href;
    if (/^(?:\.\.\/|\.\/)/.test(href)) return href;
    return rootPath() + href;
  }

  function appendQueryParam(url, key, value) {
    if (!value) return url;
    var parts = String(url).split('#');
    var path = parts[0];
    var hash = parts[1] ? ('#' + parts[1]) : '';
    var joiner = path.indexOf('?') === -1 ? '?' : '&';
    return path + joiner + encodeURIComponent(key) + '=' + encodeURIComponent(value) + hash;
  }

  function articleJumpHref(baseHref, returnHref) {
    return appendQueryParam(baseHref, 'from', returnHref);
  }

  function readJsonScript(id) {
    var script = document.getElementById(id);
    if (!script) return null;
    try {
      return JSON.parse(script.textContent);
    } catch (error) {
      console.error('JSON script lỗi định dạng:', id, error);
      return null;
    }
  }

  function getArticleMeta() {
    return readJsonScript('article-meta');
  }

  function getLegacyData() {
    return readJsonScript('article-sidebar-data');
  }

  function getContentIndex() {
    return window.KetoanDieuTamContentIndex || null;
  }

  function upsertMeta(name, content) {
    var meta = document.querySelector('meta[name="' + name + '"]');
    if (!meta) {
      meta = document.createElement('meta');
      meta.setAttribute('name', name);
      document.head.appendChild(meta);
    }
    meta.setAttribute('content', content);
  }

  function readFromParam() {
    try {
      var params = new URLSearchParams(location.search);
      return params.get('from') || '';
    } catch (error) {
      return '';
    }
  }

  function getReturnState(sectionKey) {
    if (!sectionKey) return null;
    try {
      var raw = sessionStorage.getItem('kdt:return:' + sectionKey);
      if (!raw) return null;
      return JSON.parse(raw);
    } catch (error) {
      return null;
    }
  }

  function markRestore(sectionKey) {
    if (!sectionKey) return;
    try {
      sessionStorage.setItem('kdt:return:' + sectionKey + ':restore', '1');
    } catch (error) {
      /* noop */
    }
  }

  function readReferrerFallback(data) {
    try {
      if (!document.referrer) return '';
      var base = location.href || ((location.origin || '') + (location.pathname || ''));
      var ref = new URL(document.referrer, base);
      var hubHref = String(data.hubHref || '').replace(/^\.\.\//, '/');
      if (ref.pathname === hubHref || ref.pathname === String(data.hubHref || '')) {
        return ref.pathname + ref.search;
      }
    } catch (error) {
      /* noop */
    }
    return '';
  }

  function getReturnHref(data) {
    var fromParam = readFromParam();
    if (fromParam) return fromParam;

    var referrerFallback = readReferrerFallback(data);
    if (referrerFallback) return referrerFallback;

    var returnState = getReturnState(data.sectionKey);
    if (returnState && returnState.url) return returnState.url;

    return data.hubHref || '#';
  }

  function isMobileViewport() {
    return window.matchMedia && window.matchMedia('(max-width: 768px)').matches;
  }

  function hasPixelValue(value) {
    return /-?\d+(?:\.\d+)?px/i.test(String(value || ''));
  }

  function clampLegacyMargin(value) {
    if (!hasPixelValue(value)) return value;
    var px = parseFloat(value);
    if (!isFinite(px)) return value;
    return Math.min(px, 16) + 'px';
  }

  function normalizeCellText(value) {
    return String(value || '').replace(/\s+/g, ' ').replace(/\u00a0/g, ' ').trim();
  }

  function isOperatorLabel(value) {
    return /^[=+\-*/:]+$/.test(normalizeCellText(value));
  }

  function buildMobileTableModelFromRows(rows) {
    var cleanRows = (rows || []).map(function (row) {
      return (row || []).map(function (cell, index) {
        return {
          label: cell && cell.label ? cell.label : ('Cột ' + (index + 1)),
          text: normalizeCellText(cell && cell.text),
          html: cell && cell.html ? String(cell.html).trim() : '',
          isHeader: !!(cell && cell.isHeader)
        };
      });
    }).filter(function (row) {
      return row.some(function (cell) {
        return cell.text || cell.html.replace(/&nbsp;/gi, '').trim();
      });
    });

    if (!cleanRows.length) return null;

    var maxCols = cleanRows.reduce(function (max, row) {
      return Math.max(max, row.length);
    }, 0);

    if (maxCols < 3) return null;

    var useFirstRowAsHeader = cleanRows.length > 1;
    var headers = (useFirstRowAsHeader ? cleanRows[0] : cleanRows[0]).map(function (cell, index) {
      return cell.text || cell.label || ('Cột ' + (index + 1));
    });
    var dataRows = useFirstRowAsHeader ? cleanRows.slice(1) : cleanRows;

    var cards = dataRows.map(function (row) {
      var items = [];
      for (var index = 0; index < Math.max(headers.length, row.length); index += 1) {
        var cell = row[index];
        if (!cell) continue;
        var label = headers[index] || ('Cột ' + (index + 1));
        var text = normalizeCellText(cell.text);
        var html = cell.html && cell.html.replace(/^\s*&nbsp;\s*$/i, '').trim();
        if (isOperatorLabel(label)) continue;
        if (!text && !html) continue;
        items.push({
          label: label,
          html: html || escapeHtml(text)
        });
      }
      return items.length ? { items: items } : null;
    }).filter(Boolean);

    return cards.length ? { cards: cards } : null;
  }

  function shouldBuildMobileTableCards(table) {
    var rows = Array.prototype.slice.call(table.rows || []);
    if (!rows.length) return false;
    var maxCols = rows.reduce(function (max, row) {
      return Math.max(max, (row.cells || []).length);
    }, 0);
    return maxCols >= 3;
  }

  function buildMobileTableModel(table) {
    var rows = Array.prototype.slice.call(table.rows || []).map(function (row) {
      return Array.prototype.slice.call(row.cells || []).map(function (cell, index) {
        return {
          label: cell.getAttribute && cell.getAttribute('data-label'),
          text: cell.textContent || '',
          html: cell.innerHTML || '',
          isHeader: ((cell.tagName || '').toUpperCase() === 'TH') || row.rowIndex === 0
        };
      });
    });
    return buildMobileTableModelFromRows(rows);
  }

  function renderMobileTableCards(model) {
    if (!model || !model.cards || !model.cards.length) return '';
    return '' +
      '<div class="article-table-stack">' +
        model.cards.map(function (card) {
          return '' +
            '<section class="article-table-card">' +
              card.items.map(function (item) {
                return '' +
                  '<div class="article-table-card__row">' +
                    '<div class="article-table-card__label">' + escapeHtml(item.label) + '</div>' +
                    '<div class="article-table-card__value">' + item.html + '</div>' +
                  '</div>';
              }).join('') +
            '</section>';
        }).join('') +
      '</div>';
  }

  function upsertMobileTableCards(prose, table, index) {
    if (!table.dataset.mobileTableId) {
      table.dataset.mobileTableId = 'article-table-' + index;
    }
    var selector = '.article-table-stack[data-source-table=\"' + table.dataset.mobileTableId + '\"]';
    var existing = prose.querySelector(selector);
    var model = buildMobileTableModel(table);

    if (!model) {
      table.classList.remove('article-table--mobile-origin');
      if (existing) existing.remove();
      return;
    }

    var html = renderMobileTableCards(model).replace('<div class="article-table-stack"', '<div class="article-table-stack" data-source-table="' + table.dataset.mobileTableId + '"');
    table.classList.add('article-table--mobile-origin');

    if (existing) {
      existing.outerHTML = html;
    } else {
      table.insertAdjacentHTML('afterend', html);
    }
  }

  function normalizeLegacyArticleContent() {
    var prose = document.querySelector('.article-prose');
    if (!prose) return;

    prose.querySelectorAll('table').forEach(function (table, index) {
      table.classList.add('article-table--legacy');
      if (shouldBuildMobileTableCards(table)) {
        upsertMobileTableCards(prose, table, index);
      } else if (table.dataset.mobileTableId) {
        var stale = prose.querySelector('.article-table-stack[data-source-table=\"' + table.dataset.mobileTableId + '\"]');
        table.classList.remove('article-table--mobile-origin');
        if (stale) stale.remove();
      }
    });

    prose.querySelectorAll('[style], [width], img, table, td, th, iframe, embed, object').forEach(function (node) {
      var tag = (node.tagName || '').toUpperCase();

      if (node.hasAttribute && node.hasAttribute('width')) {
        node.removeAttribute('width');
      }

      if ((tag === 'IMG' || tag === 'IFRAME' || tag === 'EMBED' || tag === 'OBJECT') && node.hasAttribute && node.hasAttribute('height')) {
        node.removeAttribute('height');
      }

      ['width', 'minWidth', 'maxWidth'].forEach(function (prop) {
        var current = node.style && node.style[prop];
        if (!current || !hasPixelValue(current)) return;

        if (tag === 'TABLE') {
          node.style[prop] = prop === 'width' ? '100%' : '';
          return;
        }

        if (tag === 'IMG' || tag === 'IFRAME' || tag === 'EMBED' || tag === 'OBJECT') {
          node.style[prop] = prop === 'width' ? '100%' : '';
          return;
        }

        node.style[prop] = '';
      });

      if (node.style) {
        if (node.style.fontFamily) node.style.fontFamily = '';
        if (node.style.fontSize) node.style.fontSize = '';
        if (node.style.lineHeight) node.style.lineHeight = '';
      }

      if (tag === 'IMG') {
        node.style.maxWidth = '100%';
        node.style.height = 'auto';
      }

      if ((tag === 'TD' || tag === 'TH')) {
        node.style.whiteSpace = 'normal';
        node.style.wordBreak = 'break-word';
        node.style.overflowWrap = 'anywhere';
      }

      if (isMobileViewport()) {
        if (node.style && node.style.marginLeft && hasPixelValue(node.style.marginLeft)) {
          node.style.marginLeft = clampLegacyMargin(node.style.marginLeft);
        }
        if (node.style && node.style.marginRight && hasPixelValue(node.style.marginRight)) {
          node.style.marginRight = clampLegacyMargin(node.style.marginRight);
        }
      }
    });
  }

  function progressLabel(data) {
    if (!data.currentIndex || !data.totalCount) return '';
    return 'Bài ' + data.currentIndex + '/' + data.totalCount;
  }

  function sectionCountLabel(data) {
    if (!data || !data.totalCount) return '';
    return data.totalCount + ' bài trong chuyên đề';
  }

  function expandArticle(index, articleId) {
    if (!index || !articleId || !index.articles || !index.articles[articleId]) return null;
    var item = index.articles[articleId];
    return {
      id: item.id,
      section: item.section,
      sectionLabel: item.sectionLabel,
      libraryKindLabel: item.libraryKindLabel || '',
      href: sitePath(item.href),
      hubHref: sitePath(item.sectionHref),
      title: item.title,
      excerpt: item.excerpt,
      topicLabel: item.topicLv2Label,
      publishDate: item.publishDate || '',
      tags: item.tags || [],
      image: item.image ? sitePath(item.image) : ''
    };
  }

  function resolveDataFromIndex() {
    var meta = getArticleMeta();
    var index = getContentIndex();
    if (!meta || !index || !index.articles || !index.articleViews) return null;
    var article = index.articles[meta.id];
    var view = index.articleViews[meta.id];
    if (!article || !view) return null;

    return {
      sectionKey: article.section,
      hubHref: sitePath(article.sectionHref),
      hubLabel: article.sectionLabel,
      topicLabel: article.topicLv2Label,
      currentTitle: article.title,
      authorName: meta.authorName || '',
      publishDate: meta.publishDate || '',
      modifiedDate: meta.modifiedDate || '',
      currentIndex: view.currentIndex,
      totalCount: view.totalCount,
      newsLatest: (view.newsLatest || []).map(function (id) { return expandArticle(index, id); }).filter(Boolean),
      libraryLatest: (view.libraryLatest || []).map(function (id) { return expandArticle(index, id); }).filter(Boolean),
      related: (view.related || []).map(function (id) { return expandArticle(index, id); }).filter(Boolean),
      latestOther: (view.latestOther || []).map(function (id) { return expandArticle(index, id); }).filter(Boolean),
      prev: expandArticle(index, view.prev),
      next: expandArticle(index, view.next)
    };
  }

  function resolveLegacyData() {
    return getLegacyData();
  }

  function resolveArticleData() {
    return resolveDataFromIndex() || resolveLegacyData();
  }

  function formatDateLabel(value) {
    if (!value) return '';
    var raw = String(value).trim();
    var m = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!m) return raw;
    return [m[3], m[2], m[1]].join('/');
  }

  function renderSidebar(data, returnHref) {
    var shellConfig = window.KetoanDieuTamShellConfig || {};
    var brand = shellConfig.brand || {};
    var hotlineLink = brand.hotlineLink || '0777315188';
    var newsItems = (data.newsLatest || []).map(function (item) {
      return '' +
        '<li>' +
          '<a class="article-side__news-link" href="' + escapeHtml(articleJumpHref(item.href, returnHref)) + '">' +
            '<strong>' + escapeHtml(item.title) + '</strong>' +
            (item.publishDate ? '<small>' + escapeHtml(formatDateLabel(item.publishDate)) + '</small>' : '') +
          '</a>' +
        '</li>';
    }).join('');

    var libraryItems = (data.libraryLatest || []).map(function (item) {
      var jumpClass = 'article-side__jump' + (item.image ? '' : ' article-side__jump--no-thumb');
      var label = item.libraryKindLabel || 'Thư viện';
      return '' +
        '<li>' +
          '<a class="' + jumpClass + '" href="' + escapeHtml(articleJumpHref(item.href, returnHref)) + '">' +
            (item.image
              ? '<span class="article-side__thumb"><img loading="lazy" decoding="async" src="' + escapeHtml(item.image) + '" alt="' + escapeHtml(item.title) + '"></span>'
              : '') +
            '<span class="article-side__jump-body">' +
              '<small>' + escapeHtml(label) + '</small>' +
              '<strong>' + escapeHtml(item.title) + '</strong>' +
            '</span>' +
          '</a>' +
        '</li>';
    }).join('');

    return '' +
      ((data.newsLatest || []).length
        ? '<section class="article-side__section article-side__section--news">' +
            '<div class="article-side__section-head"><h4>Bản tin mới</h4></div>' +
            '<ul class="article-side__news-list">' + newsItems + '</ul>' +
            ((data.newsLatest || []).length > 2
              ? '<a class="article-side__more-link" href="' + escapeHtml(sitePath('ban-tin.html')) + '">Xem thêm Bản tin<i class="fa-solid fa-arrow-right"></i></a>'
              : '') +
          '</section>'
        : '') +
      ((data.libraryLatest || []).length
        ? '<section class="article-side__section article-side__section--library">' +
            '<div class="article-side__section-head"><h4>Mới trong Thư viện</h4></div>' +
            '<ul class="article-side__list">' + libraryItems + '</ul>' +
            ((data.libraryLatest || []).length > 2
              ? '<a class="article-side__more-link" href="' + escapeHtml(sitePath('thu-vien.html')) + '">Xem thêm Thư viện<i class="fa-solid fa-arrow-right"></i></a>'
              : '') +
          '</section>'
        : '') +
      '<div class="article-cta-box">' +
        '<p>Cần người đồng hành khi áp dụng vào thực tế?</p>' +
        '<a href="https://zalo.me/' + hotlineLink + '" class="btn btn-primary-orange" target="_blank" rel="noopener">Trao đổi qua Zalo</a>' +
      '</div>';
  }

  function renderTopNav(data, returnHref) {
    var metaItems = [];
    if (data.authorName) {
      metaItems.push('<span class="article-meta-inline article-meta-inline--author">Biên soạn: ' + escapeHtml(data.authorName) + '</span>');
    }
    if (data.publishDate) {
      metaItems.push(
        '<time class="article-meta-inline article-meta-inline--date" datetime="' + escapeHtml(String(data.publishDate)) + '">' +
          'Đăng ngày ' + escapeHtml(formatDateLabel(data.publishDate)) +
        '</time>'
      );
    } else if (data.modifiedDate) {
      metaItems.push(
        '<time class="article-meta-inline article-meta-inline--date" datetime="' + escapeHtml(String(data.modifiedDate)) + '">' +
          'Cập nhật ' + escapeHtml(formatDateLabel(data.modifiedDate)) +
        '</time>'
      );
    }

    return '' +
      '<div class="article-top-nav__group">' +
        '<div class="article-top-nav__meta">' + metaItems.join('') + '</div>' +
        '<a id="articleTopBack" class="article-back-link" href="' + escapeHtml(returnHref) + '">' +
          '<i class="fa-solid fa-arrow-left"></i>' +
          '<span>Về danh sách</span>' +
        '</a>' +
      '</div>';
  }

  function renderContinueCard(item, direction, returnHref) {
    if (!item) return '';
    var icon = direction === 'prev' ? 'fa-arrow-left' : 'fa-arrow-right';
    var label = direction === 'prev' ? 'Bài trước đó' : 'Bài tiếp theo';
    var modifier = direction === 'prev' ? 'article-next__card--secondary' : 'article-next__card--primary';
    return '' +
      '<a class="article-next__card ' + modifier + '" href="' + escapeHtml(articleJumpHref(item.href, returnHref)) + '">' +
        '<span class="article-next__label"><i class="fa-solid ' + icon + '"></i>' + label + '</span>' +
        '<strong class="article-next__title">' + escapeHtml(item.title) + '</strong>' +
        (item.topicLabel ? '<small class="article-next__topic">' + escapeHtml(item.topicLabel) + '</small>' : '') +
      '</a>';
  }

  function renderBottomNav(data, returnHref) {
    var cards = [];
    if (data.prev) cards.push(renderContinueCard(data.prev, 'prev', returnHref));
    if (data.next) cards.push(renderContinueCard(data.next, 'next', returnHref));

    return '' +
      '<div class="article-next">' +
        '<div class="article-next__head">' +
          '<span class="article-next__eyebrow">Đọc tiếp</span>' +
        '</div>' +
        (cards.length ? '<div class="article-next__grid">' + cards.join('') + '</div>' : '') +
        '<div class="article-next__actions">' +
          '<a id="articleBottomBack" class="article-next__action article-next__action--back" href="' + escapeHtml(returnHref) + '">' +
            '<i class="fa-solid fa-list-ul"></i>' +
            '<span>Về danh sách</span>' +
          '</a>' +
        '</div>' +
      '</div>';
  }

  function renderRelatedPrimary(items, returnHref) {
    if (!items || !items.length) return '';
    var rows = items.map(function (item) {
      var rowClass = 'article-link-row' + (item.image ? '' : ' article-link-row--no-thumb');
      return '' +
        '<a class="' + rowClass + '" href="' + escapeHtml(articleJumpHref(item.href, returnHref)) + '">' +
          (item.image
            ? '<span class="article-link-row__thumb"><img loading="lazy" decoding="async" src="' + escapeHtml(item.image) + '" alt="' + escapeHtml(item.title) + '"></span>'
            : '') +
          '<div class="article-link-row__body">' +
            (item.topicLabel ? '<small class="article-link-row__eyebrow">' + escapeHtml(item.topicLabel) + '</small>' : '') +
            '<strong>' + escapeHtml(item.title) + '</strong>' +
          '</div>' +
          '<i class="fa-solid fa-arrow-right"></i>' +
        '</a>';
    }).join('');

    return '' +
      '<section class="article-discover article-discover--primary">' +
        '<div class="article-discover__head">' +
          '<h3>Cùng chuyên đề</h3>' +
        '</div>' +
        '<div class="article-link-list">' + rows + '</div>' +
      '</section>';
  }

  function renderLatestOther(items, returnHref) {
    if (!items || !items.length) return '';
    var cards = items.map(function (item) {
      var cardClass = 'article-mini-card' + (item.image ? '' : ' article-mini-card--no-thumb');
      var label = item.section === 'ban-tin' ? 'Bản tin' : (item.libraryKindLabel || item.sectionLabel || '');
      return '' +
        '<a class="' + cardClass + '" href="' + escapeHtml(articleJumpHref(item.href, returnHref)) + '">' +
          (item.image
            ? '<span class="article-mini-card__media"><img loading="lazy" decoding="async" src="' + escapeHtml(item.image) + '" alt="' + escapeHtml(item.title) + '"></span>'
            : '') +
          '<span class="article-mini-card__body">' +
            '<span class="article-mini-card__label">' + escapeHtml(label) + '</span>' +
            '<strong>' + escapeHtml(item.title) + '</strong>' +
            (item.topicLabel ? '<small>' + escapeHtml(item.topicLabel) + '</small>' : '') +
          '</span>' +
        '</a>';
    }).join('');

      return '' +
      '<section class="article-discover article-discover--secondary">' +
        '<div class="article-discover__head">' +
          '<h3>Gợi ý thêm</h3>' +
        '</div>' +
        '<div class="article-mini-grid">' + cards + '</div>' +
      '</section>';
  }

  function renderRecommendations(data, returnHref) {
    return (
      renderRelatedPrimary(data.related || [], returnHref) +
      renderLatestOther(data.latestOther || [], returnHref)
    );
  }

  function renderMobileNav(data, returnHref) {
    return '' +
      '<div class="article-mobile-nav__inner">' +
        (data.prev
          ? '<a class="article-mobile-nav__item" href="' + escapeHtml(articleJumpHref(data.prev.href, returnHref)) + '">' +
              '<i class="fa-solid fa-arrow-left"></i><span>Trước</span>' +
            '</a>'
          : '<span class="article-mobile-nav__item is-disabled"><i class="fa-solid fa-arrow-left"></i><span>Trước</span></span>') +
        '<a id="articleMobileBack" class="article-mobile-nav__item is-primary" href="' + escapeHtml(returnHref) + '">' +
          '<i class="fa-solid fa-list-ul"></i><span>Danh sách</span>' +
        '</a>' +
        (data.next
          ? '<a class="article-mobile-nav__item" href="' + escapeHtml(articleJumpHref(data.next.href, returnHref)) + '">' +
              '<span>Sau</span><i class="fa-solid fa-arrow-right"></i>' +
            '</a>'
          : '<span class="article-mobile-nav__item is-disabled"><span>Sau</span><i class="fa-solid fa-arrow-right"></i></span>') +
      '</div>';
  }

  function attachReturnHandlers(data, returnHref) {
    [
      document.getElementById('articleSidebarBack'),
      document.getElementById('articleHubBreadcrumb'),
      document.getElementById('articleTopBack'),
      document.getElementById('articleBottomBack'),
      document.getElementById('articleMobileBack')
    ].forEach(function (link) {
      if (!link) return;
      link.setAttribute('href', returnHref);
      link.addEventListener('click', function () {
        markRestore(data.sectionKey);
      });
    });
  }

  function ensureMobileNavHost() {
    var existing = document.getElementById('articleMobileNav');
    if (existing) return existing;
    var host = document.createElement('div');
    host.id = 'articleMobileNav';
    host.className = 'article-mobile-nav';
    document.body.appendChild(host);
    return host;
  }

  function isStackedArticleLayout() {
    return window.matchMedia && window.matchMedia('(max-width: 1180px)').matches;
  }

  function syncArticleAuxLayout(sidebarHost, recommendationsHost) {
    if (!sidebarHost) return;
    var grid = document.querySelector('.article-grid');
    var main = document.querySelector('.article-main');
    if (!grid || !main) return;

    if (isStackedArticleLayout()) {
      if (sidebarHost.parentNode !== main) {
        if (recommendationsHost && recommendationsHost.parentNode === main) {
          main.insertBefore(sidebarHost, recommendationsHost);
        } else {
          main.appendChild(sidebarHost);
        }
      }
    } else if (sidebarHost.parentNode !== grid) {
      grid.appendChild(sidebarHost);
    }
  }

  function initArticleLayout() {
    var data = resolveArticleData();
    var sidebarHost = document.getElementById('articleSidebar');
    if (!data || !sidebarHost) return;

    normalizeLegacyArticleContent();

    var returnHref = getReturnHref(data);
    upsertMeta('robots', readFromParam() ? 'noindex,follow' : 'index,follow');
    var topNavHost = document.getElementById('articleTopNav');
    var bottomNavHost = document.getElementById('articleBottomNav');
    var recommendationsHost = document.getElementById('articleRecommendations');
    var mobileNavHost = ensureMobileNavHost();

    sidebarHost.innerHTML = renderSidebar(data, returnHref);
    if (topNavHost) topNavHost.innerHTML = renderTopNav(data, returnHref);
    if (bottomNavHost) bottomNavHost.innerHTML = renderBottomNav(data, returnHref);
    if (recommendationsHost) recommendationsHost.innerHTML = renderRecommendations(data, returnHref);
    syncArticleAuxLayout(sidebarHost, recommendationsHost);
    if (mobileNavHost) mobileNavHost.innerHTML = renderMobileNav(data, returnHref);

    attachReturnHandlers(data, returnHref);
  }

  document.addEventListener('DOMContentLoaded', initArticleLayout);
  window.addEventListener('resize', normalizeLegacyArticleContent, { passive: true });
  window.addEventListener('resize', function () {
    syncArticleAuxLayout(document.getElementById('articleSidebar'), document.getElementById('articleRecommendations'));
  }, { passive: true });
})();
