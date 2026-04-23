(function () {
  var config = {
    brand: {
      logo: 'assets/images/site/logo.png',
      name: 'KẾ TOÁN DIỆU TÂM',
      tagline: 'Minh bạch tài chính – Vững nền tương lai',
      hotline: '0777 315 188',
      hotlineLink: '0777315188',
      email: 'ketoandieutam@gmail.com',
      address: '3/53/8 Thành Thái, P. Diên Hồng, TP.HCM'
    },
		    nav: [
		      { key: 'home', label: 'Trang Chủ', href: 'index.html' },
			      { key: 'gioi-thieu', label: 'Giới Thiệu', href: 'gioi-thieu.html' },
			      { key: 'giai-phap', label: 'Giải Pháp', href: 'giai-phap.html' },
			      { key: 'dao-tao', label: 'Đào Tạo', href: 'dao-tao.html' },
			      { key: 'tuyen-dung', label: 'Tuyển Dụng', href: 'tuyen-dung.html' },
			      {
			        key: 'thu-vien',
		        label: 'Thư Viện',
		        href: 'thu-vien.html',
		        children: [
		          { key: 'thu-vien-huong-dan', label: 'Hướng dẫn', href: 'thu-vien.html?kind=huong-dan' },
		          { key: 'thu-vien-bieu-mau', label: 'Biểu mẫu', href: 'thu-vien.html?kind=bieu-mau' },
		          { key: 'thu-vien-cong-cu', label: 'Công cụ', href: 'thu-vien.html?kind=cong-cu' },
		          { key: 'thu-vien-van-ban', label: 'Văn bản', href: 'thu-vien.html?kind=van-ban' }
		        ]
		      },
		      { key: 'ban-tin', label: 'Bản Tin', href: 'ban-tin.html' },
		      { key: 'lien-he', label: 'Liên Hệ', href: 'lien-he.html' }
		    ]
	  };

  function path(root, href) {
    if (!href) return '#';
    if (/^(https?:|mailto:|tel:|#)/i.test(href)) return href;
    return root + href;
  }

	  function renderHeader(root, activeKey) {
	    var currentKind = '';
	    try {
	      currentKind = new URLSearchParams(window.location.search || '').get('kind') || '';
	    } catch (error) {
	      currentKind = '';
	    }
	    var navItems = config.nav.map(function (item) {
	      var childItems = (item.children || []).map(function (child) {
		        var childActive = child.href.indexOf('kind=') !== -1 && currentKind
		          ? child.href.indexOf('kind=' + currentKind) !== -1
		          : false;
		        return '<li><a href="' + path(root, child.href) + '" data-label="' + child.label + '" class="' + (childActive ? 'active' : '') + '"><span class="nav-submenu-label">' + child.label + '</span></a></li>';
	      }).join('');
	      var active = (item.key === activeKey || childItems.indexOf('class="active"') !== -1) ? 'active' : '';
	      return '' +
	        '<li class="' + (item.children ? 'has-submenu' : '') + '">' +
	          '<a href="' + path(root, item.href) + '" class="' + active + '">' +
	            '<span>' + item.label + '</span>' +
	            (item.children ? '<i class="fa-solid fa-angle-down nav-caret" aria-hidden="true"></i>' : '') +
	          '</a>' +
	          (item.children ? '<ul class="nav-submenu">' + childItems + '</ul>' : '') +
	        '</li>';
	    }).join('');

    return '' +
      '<header class="top-header">' +
        '<div class="container header-row">' +
          '<div class="brand-block">' +
            '<a href="' + path(root, 'index.html') + '" class="brand-logo"><img src="' + path(root, config.brand.logo) + '" alt="Kế Toán Diệu Tâm"></a>' +
            '<div class="brand-text">' +
              '<h1>' + config.brand.name + '</h1>' +
              '<p>' + config.brand.tagline + '</p>' +
            '</div>' +
          '</div>' +
          '<button class="hamburger" id="hamburgerBtn" aria-label="Menu">' +
            '<span></span><span></span><span></span>' +
          '</button>' +
          '<div class="header-contact">' +
            '<div class="hotline-box">' +
              '<div class="hotline-icon"><i class="fa-solid fa-phone-volume"></i></div>' +
              '<div class="hotline-text">' +
                '<small>Hotline 24/7</small>' +
                '<strong>' + config.brand.hotline + '</strong>' +
              '</div>' +
            '</div>' +
            '<a href="https://zalo.me/' + config.brand.hotlineLink + '" class="btn-zalo-gold" target="_blank" rel="noopener">Tư vấn Zalo</a>' +
          '</div>' +
        '</div>' +
      '</header>' +
      '<nav class="main-nav" id="navbar">' +
        '<div class="container">' +
          '<ul id="mainMenu">' + navItems + '</ul>' +
        '</div>' +
      '</nav>';
  }

  function renderFooter(root) {
    return '' +
      '<footer class="site-footer">' +
        '<div class="container">' +
          '<div class="footer-grid">' +
            '<div class="footer-brand">' +
              '<h3>Kế Toán Diệu Tâm</h3>' +
              '<p class="footer-tagline">' + config.brand.tagline + '</p>' +
              '<p class="footer-desc">Đơn vị tư vấn quản trị doanh nghiệp và đào tạo Kế toán – HCNS thực chiến dành cho doanh nghiệp nhỏ và vừa tại Việt Nam.</p>' +
            '</div>' +
	            '<div class="footer-links">' +
	              '<h4>Liên kết</h4>' +
	              '<ul>' +
	                '<li><a href="' + path(root, 'gioi-thieu.html') + '">Giới Thiệu</a></li>' +
	                '<li><a href="' + path(root, 'giai-phap.html') + '">Giải Pháp</a></li>' +
	                '<li><a href="' + path(root, 'dao-tao.html') + '">Đào Tạo</a></li>' +
	                '<li><a href="' + path(root, 'tuyen-dung.html') + '">Tuyển Dụng</a></li>' +
		                '<li><a href="' + path(root, 'thu-vien.html') + '">Thư Viện</a></li>' +
	              '</ul>' +
	            '</div>' +
            '<div class="footer-connect">' +
              '<h4>Kết nối</h4>' +
              '<ul>' +
                '<li><a href="https://zalo.me/' + config.brand.hotlineLink + '">Zalo OA Công ty</a></li>' +
                '<li><a href="#">Cộng đồng Zalo</a></li>' +
                '<li><a href="https://facebook.com/ketoandieutam">Fanpage Công ty</a></li>' +
              '</ul>' +
            '</div>' +
            '<div class="footer-contact">' +
              '<h4>Liên hệ</h4>' +
              '<ul>' +
                '<li><i class="fa-solid fa-phone"></i> <a href="tel:' + config.brand.hotlineLink + '">' + config.brand.hotline + '</a></li>' +
                '<li><i class="fa-solid fa-envelope"></i> <a href="mailto:' + config.brand.email + '">' + config.brand.email + '</a></li>' +
                '<li><i class="fa-solid fa-location-dot"></i> ' + config.brand.address + '</li>' +
              '</ul>' +
            '</div>' +
          '</div>' +
          '<div class="footer-bottom"><p>© 2026 Kế Toán Diệu Tâm. All rights reserved.</p></div>' +
        '</div>' +
      '</footer>' +
      '<button id="backToTop" class="fab-top" aria-label="Lên đầu trang"><i class="fa-solid fa-arrow-up"></i></button>' +
      '<div class="contact-action-bar" id="contactBar">' +
        '<a href="tel:' + config.brand.hotlineLink + '" class="cab-item cab-call mobile-only" aria-label="Gọi điện"><i class="fa-solid fa-phone"></i></a>' +
        '<a href="https://zalo.me/' + config.brand.hotlineLink + '" target="_blank" class="cab-item cab-zalo" aria-label="Nhắn Zalo" rel="noopener">' +
          '<div class="zalo-css-logo desktop-only">Zalo</div>' +
          '<div class="mobile-zalo-group"><i class="fa-solid fa-comment-dots"></i><span>Zalo</span></div>' +
        '</a>' +
        '<a href="https://m.me/ketoandieutam" target="_blank" class="cab-item cab-messenger" aria-label="Nhắn Messenger" rel="noopener"><i class="fa-brands fa-facebook-messenger"></i></a>' +
      '</div>';
  }

  function initInteractions() {
    var navbar = document.getElementById('navbar');
    var topHeader = document.querySelector('.top-header');
    function isMobileHeaderMode() {
      return window.innerWidth <= 767;
    }
    function syncMobileHeaderCompactState() {
      if (!topHeader) return;
      if (!isMobileHeaderMode()) {
        document.body.classList.remove('mobile-header-compact');
        return;
      }
      document.body.classList.toggle('mobile-header-compact', window.scrollY > 8);
    }
    syncMobileHeaderCompactState();
    if (navbar) {
      window.addEventListener('scroll', function () {
        if (window.scrollY > 50) {
          navbar.style.background = '#A57A3A';
          navbar.style.boxShadow = '0 10px 30px rgba(0,0,0,0.1)';
        } else {
          navbar.style.background = '#C49A54';
          navbar.style.boxShadow = 'none';
        }
        syncMobileHeaderCompactState();
      });
    } else {
      window.addEventListener('scroll', syncMobileHeaderCompactState);
    }

    var hamburger = document.getElementById('hamburgerBtn');
    var menu = document.getElementById('mainMenu');
    var submenuParents = [];
    if (menu && menu.children) {
      submenuParents = Array.prototype.filter.call(menu.children, function (item) {
        return item && item.classList && item.classList.contains('has-submenu');
      });
    }
    function isMobileMenuMode() {
      return window.innerWidth <= 991;
    }
    function collapseAllSubmenus() {
      submenuParents.forEach(function (item) {
        item.classList.remove('is-expanded');
        item.classList.remove('submenu-open');
      });
    }
    function setupMobileSubmenuToggle() {
      submenuParents.forEach(function (item) {
        var trigger = null;
        if (item && item.firstElementChild && item.firstElementChild.tagName.toLowerCase() === 'a') {
          trigger = item.firstElementChild;
        } else if (item) {
          trigger = item.querySelector('a');
        }
        if (!trigger) return;
        if (trigger.dataset.mobileSubmenuBound === '1') return;
        trigger.dataset.mobileSubmenuBound = '1';
        trigger.addEventListener('click', function (event) {
          if (!isMobileMenuMode()) return;
          event.preventDefault();
          event.stopPropagation();
          var willExpand = !item.classList.contains('submenu-open');
          collapseAllSubmenus();
          if (willExpand) {
            item.classList.add('submenu-open');
          }
        });
      });
    }
    function syncBodyMenuState() {
      if (!menu) return;
      document.body.classList.toggle('menu-open', menu.classList.contains('open'));
      if (!menu.classList.contains('open')) {
        collapseAllSubmenus();
      }
    }
    if (hamburger && menu) {
      hamburger.addEventListener('click', function () {
        if (!menu.classList.contains('open')) {
          collapseAllSubmenus();
        }
        menu.classList.toggle('open');
        syncBodyMenuState();
      });
    }

    setupMobileSubmenuToggle();

    window.addEventListener('resize', function () {
      syncMobileHeaderCompactState();
      if (!menu) return;
      if (window.innerWidth > 991 && menu.classList.contains('open')) {
        menu.classList.remove('open');
      }
      if (!isMobileMenuMode()) {
        collapseAllSubmenus();
      }
      syncBodyMenuState();
    });

    document.addEventListener('click', function (event) {
      if (!menu || !menu.contains(event.target)) {
        collapseAllSubmenus();
      }
    });

    var backToTopBtn = document.getElementById('backToTop');
    var contactBar = document.getElementById('contactBar');
    var scrollTimeout;

    window.addEventListener('scroll', function () {
      if (backToTopBtn) {
        if (window.scrollY > 400) backToTopBtn.classList.add('show');
        else backToTopBtn.classList.remove('show');
        backToTopBtn.classList.add('is-scrolling');
      }
      if (contactBar) contactBar.classList.add('is-scrolling');

      clearTimeout(scrollTimeout);
      scrollTimeout = setTimeout(function () {
        if (contactBar) contactBar.classList.remove('is-scrolling');
        if (backToTopBtn) backToTopBtn.classList.remove('is-scrolling');
      }, 800);
    });

    if (backToTopBtn) {
      backToTopBtn.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    }

    syncBodyMenuState();
  }

  function normalizeFlatBreadcrumb(container) {
    if (!container) return;

    var nodes = Array.prototype.slice.call(container.childNodes || []);
    var items = [];

    nodes.forEach(function (node) {
      if (node.nodeType === 3) {
        var text = (node.textContent || '').trim();
        if (!text) return;
        if (/^[\/>]+$/.test(text)) return;
        items.push(node.cloneNode(true));
        return;
      }

      if (node.nodeType !== 1) return;

      var tagName = (node.tagName || '').toLowerCase();
      var textContent = (node.textContent || '').trim();
      var classList = node.classList || { contains: function () { return false; } };

      if (tagName === 'i' && classList.contains('fa-angle-right')) return;
      if ((tagName === 'span' || tagName === 'li') && /^[\/>]+$/.test(textContent)) return;

      items.push(node.cloneNode(true));
    });

    if (items.length < 2) return;

    container.innerHTML = '';
    items.forEach(function (item, index) {
      container.appendChild(item);
      if (index >= items.length - 1) return;
      var separator = document.createElement('i');
      separator.className = 'fa-solid fa-angle-right';
      separator.setAttribute('aria-hidden', 'true');
      container.appendChild(separator);
    });
  }

  function normalizeBreadcrumbs(scope) {
    var root = scope || document;
    var selectors = [
      '.about-breadcrumbs',
      '.jobs-breadcrumbs',
      '.catalog-breadcrumbs',
      '.article-breadcrumbs'
    ];
    selectors.forEach(function (selector) {
      var list = root.querySelectorAll(selector);
      list.forEach(normalizeFlatBreadcrumb);
    });
  }

  function getJobSlugFromLocation() {
    var pathname = window.location.pathname || '';
    var match = pathname.match(/(?:^|\/)tuyen-dung\/([^/?#]+)\.html$/i);
    if (match && match[1]) {
      return decodeURIComponent(match[1]);
    }

    var canonical = document.querySelector('link[rel="canonical"]');
    if (canonical && canonical.href) {
      var canonicalMatch = canonical.href.match(/(?:^|\/)tuyen-dung\/([^/?#]+)\.html$/i);
      if (canonicalMatch && canonicalMatch[1]) {
        return decodeURIComponent(canonicalMatch[1]);
      }
    }

    var href = window.location.href || '';
    var hrefMatch = href.match(/(?:^|\/)tuyen-dung\/([^/?#]+)\.html/i);
    if (hrefMatch && hrefMatch[1]) {
      return decodeURIComponent(hrefMatch[1]);
    }

    return '';
  }

  function buildUrlWithQuery(root, targetPath, query, hash) {
    var href = path(root, targetPath);
    var parts = [];
    Object.keys(query || {}).forEach(function (key) {
      var value = query[key];
      if (value === undefined || value === null || value === '') return;
      parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(String(value)));
    });
    if (parts.length) {
      href += (href.indexOf('?') === -1 ? '?' : '&') + parts.join('&');
    }
    if (hash) {
      href += hash.charAt(0) === '#' ? hash : ('#' + hash);
    }
    return href;
  }

  function hydrateJobDetailActions() {
    var body = document.body;
    if (!body || !body.classList.contains('job-detail-page')) return;

    var root = body.dataset.root || '';
    var slug = body.dataset.jobId || body.dataset.jobIdSlug || getJobSlugFromLocation();
    if (!slug) return;
    body.dataset.jobIdSlug = slug;

    var isExpired = !!document.querySelector('.job-badge.expired');
    var applyTargets = document.querySelectorAll('.job-detail-actions .btn-primary-orange, .job-apply-bottom-actions .btn-primary-orange, .job-detail-mobile-bar .btn-primary-orange');
    var saveTargets = document.querySelectorAll('.job-detail-actions .btn-outline-brown, .job-detail-mobile-bar .btn-outline-brown');

    Array.prototype.forEach.call(applyTargets, function (anchor) {
      if (!anchor || !anchor.getAttribute) return;
      if (isExpired) {
        anchor.setAttribute('href', buildUrlWithQuery(root, 'tuyen-dung.html', {}, 'job-list'));
        anchor.textContent = 'Xem tin tương tự';
        return;
      }
      anchor.setAttribute('href', buildUrlWithQuery(root, 'ung-tuyen.html', {
        job_id: slug,
        mode: 'nop-moi',
        from: 'chi-tiet-tin'
      }));
    });

    Array.prototype.forEach.call(saveTargets, function (anchor) {
      if (!anchor || !anchor.getAttribute) return;
      var label = (anchor.textContent || '').toLowerCase();
      if (label.indexOf('lưu việc làm') === -1 && label.indexOf('luu viec lam') === -1) return;
      anchor.setAttribute('href', buildUrlWithQuery(root, 'viec-lam-da-luu.html', {
        action: 'luu-viec',
        job_id: slug,
        from: 'chi-tiet-tin'
      }));
    });

    if (!isExpired) return;

    var hintTargets = [
      document.querySelector('.job-detail-actions'),
      document.querySelector('.job-apply-bottom')
    ];
      hintTargets.forEach(function (target) {
        if (!target || target.querySelector('.jobs-expired-hint')) return;
        var hint = document.createElement('p');
        hint.className = 'jobs-dashboard-note jobs-expired-hint';
        hint.textContent = 'Tin này đã hết hạn nhận hồ sơ. Bạn có thể xem các tin còn đang tuyển.';
        target.appendChild(hint);
      });
  }

  function hydrateCandidatePublicProfileActions() {
    var body = document.body;
    if (!body) return;

    var pathname = window.location.pathname || '';
    var match = pathname.match(/(?:^|\/)ung-vien\/([^/?#]+)\.html$/i);
    if (!match || !match[1]) return;

    var root = body.dataset.root || '';
    var candidateSlug = decodeURIComponent(match[1]);
    var requestParams = {
      view: 'ho-so-phu-hop',
      candidate_id: 'candidate/' + candidateSlug,
      from: 'ho-so-cong-khai'
    };
    var loginParams = {
      from: 'ho-so-cong-khai',
      candidate_id: 'candidate/' + candidateSlug
    };

    var recruiterLinks = document.querySelectorAll('a[data-candidate-request-open], a[href$=\"ung-vien-tuyen-dung.html\"], a[href^=\"../ung-vien-tuyen-dung.html\"]');
    Array.prototype.forEach.call(recruiterLinks, function (anchor) {
      if (!anchor || !anchor.getAttribute) return;
      anchor.setAttribute('href', buildUrlWithQuery(root, 'ung-vien-tuyen-dung.html', requestParams));
      var linkText = (anchor.textContent || '').trim().toLowerCase();
      if (linkText.indexOf('mở trang ứng viên tuyển dụng') !== -1) {
        anchor.textContent = 'Xem đơn ứng tuyển theo hồ sơ';
      } else if (linkText.indexOf('yêu cầu kết nối') !== -1) {
        anchor.textContent = 'Gửi yêu cầu mở liên hệ';
      }
    });

    var loginLinks = document.querySelectorAll('a[href$=\"dang-nhap-tuyen-dung.html\"], a[href^=\"../dang-nhap-tuyen-dung.html\"]');
    Array.prototype.forEach.call(loginLinks, function (anchor) {
      if (!anchor || !anchor.getAttribute) return;
      anchor.setAttribute('href', buildUrlWithQuery(root, 'dang-nhap-tuyen-dung.html', loginParams));
      var loginText = (anchor.textContent || '').trim().toLowerCase();
      if (loginText.indexOf('xem liên hệ') !== -1) {
        anchor.textContent = 'Đăng nhập để xem liên hệ';
      }
    });
  }

  function hydrateJobsListSaveActions() {
    var body = document.body;
    if (!body || !body.classList.contains('jobs-list-page')) return;

    var root = body.dataset.root || '';
    var cards = document.querySelectorAll('.job-card');
    if (!cards || !cards.length) return;

    function getJobSlugFromCard(card) {
      if (!card) return '';
      var link = card.querySelector('.job-card-stretched-link');
      if (!link) return '';
      var href = link.getAttribute('href') || '';
      var match = href.match(/(?:^|\/)tuyen-dung\/([^/?#]+)\.html/i);
      return match && match[1] ? decodeURIComponent(match[1]) : '';
    }

    Array.prototype.forEach.call(cards, function (card) {
      var saveBtn = card.querySelector('.job-card-save-btn');
      if (!saveBtn) return;

      var slug = getJobSlugFromCard(card);
      if (!slug) return;
      var titleEl = card.querySelector('.job-card-stretched-link');
      var title = titleEl ? (titleEl.textContent || '').trim() : 'tin tuyển dụng';

      saveBtn.setAttribute('aria-pressed', 'false');
      saveBtn.setAttribute('data-job-id', slug);
      saveBtn.title = 'Lưu việc làm';

      saveBtn.addEventListener('click', function () {
        var pressed = saveBtn.getAttribute('aria-pressed') === 'true';
        if (pressed) {
          saveBtn.setAttribute('aria-pressed', 'false');
          saveBtn.title = 'Lưu việc làm';
          saveBtn.innerHTML = '<i class=\"fa-regular fa-bookmark\" aria-hidden=\"true\"></i>';
          return;
        }

        saveBtn.setAttribute('aria-pressed', 'true');
        saveBtn.title = 'Đã lưu việc làm';
        saveBtn.innerHTML = '<i class=\"fa-solid fa-bookmark\" aria-hidden=\"true\"></i>';
        window.setTimeout(function () {
          window.location.href = buildUrlWithQuery(root, 'viec-lam-da-luu.html', {
            action: 'luu-viec',
            job_id: slug,
            from: 'danh-sach-viec-lam',
            title: title
          });
        }, 80);
      });
    });
  }

  function renderShell() {
    var body = document.body;
    if (!body) return;

    var root = body.dataset.root || '';
    var active = body.dataset.nav || '';
    var headerHost = document.getElementById('siteHeader');
    var footerHost = document.getElementById('siteFooter');

    if (headerHost) headerHost.innerHTML = renderHeader(root, active);
    if (footerHost) footerHost.innerHTML = renderFooter(root);

    initInteractions();
    normalizeBreadcrumbs(document);
    window.setTimeout(function () { normalizeBreadcrumbs(document); }, 80);
    hydrateJobDetailActions();
    hydrateCandidatePublicProfileActions();
    hydrateJobsListSaveActions();
    document.dispatchEvent(new CustomEvent('site-shell-ready'));
  }

  window.KetoanDieuTamShellConfig = config;
  document.addEventListener('DOMContentLoaded', renderShell);
})();
