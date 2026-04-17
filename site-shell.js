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
	        return '<li><a href="' + path(root, child.href) + '" class="' + (childActive ? 'active' : '') + '">' + child.label + '</a></li>';
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
    if (navbar) {
      window.addEventListener('scroll', function () {
        if (window.scrollY > 50) {
          navbar.style.background = '#A57A3A';
          navbar.style.boxShadow = '0 10px 30px rgba(0,0,0,0.1)';
        } else {
          navbar.style.background = '#C49A54';
          navbar.style.boxShadow = 'none';
        }
      });
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
        var submenu = item.querySelector('.nav-submenu');
        if (submenu) submenu.style.display = 'none';
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
          var submenu = item.querySelector('.nav-submenu');
          var submenuLinks = submenu ? Array.prototype.slice.call(submenu.querySelectorAll('a')) : [];
          var willExpand = !item.classList.contains('is-expanded');
          collapseAllSubmenus();
          if (willExpand) {
            item.classList.add('is-expanded');
            if (submenu) {
              submenu.style.display = 'block';
              submenu.style.opacity = '1';
              submenu.style.visibility = 'visible';
            }
            submenuLinks.forEach(function (link) {
              link.style.display = 'block';
              link.style.visibility = 'visible';
              link.style.opacity = '1';
              link.style.color = '#ffffff';
              link.style.webkitTextFillColor = '#ffffff';
              link.style.background = 'rgba(109, 71, 40, 0.92)';
              link.style.borderLeft = '3px solid rgba(240, 215, 168, 0.9)';
            });
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
      if (!menu) return;
      if (window.innerWidth > 991 && menu.classList.contains('open')) {
        menu.classList.remove('open');
      }
      if (!isMobileMenuMode()) {
        collapseAllSubmenus();
        submenuParents.forEach(function (item) {
          var submenu = item.querySelector('.nav-submenu');
          if (submenu) submenu.style.display = '';
        });
      }
      syncBodyMenuState();
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
    document.dispatchEvent(new CustomEvent('site-shell-ready'));
  }

  window.KetoanDieuTamShellConfig = config;
  document.addEventListener('DOMContentLoaded', renderShell);
})();
