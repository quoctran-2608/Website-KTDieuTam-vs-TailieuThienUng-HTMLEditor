(function () {
  var config = {
    brand: {
      logo: 'assets/images/site/logo.jpg',
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
		      { key: 'luat-thue', label: 'Luật Thuế Mới', href: 'luat-thue-moi.html' },
		      { key: 'dao-tao', label: 'Đào Tạo', href: 'dao-tao.html' },
		      {
		        key: 'thu-vien',
		        label: 'Thư Viện',
		        href: 'thu-vien.html',
		        children: [
		          { key: 'thu-vien-huong-dan', label: 'Hướng dẫn', href: 'thu-vien.html?kind=huong-dan' },
		          { key: 'thu-vien-bieu-mau', label: 'Biểu mẫu', href: 'thu-vien.html?kind=bieu-mau' },
		          { key: 'thu-vien-cong-cu', label: 'Công cụ', href: 'thu-vien.html?kind=cong-cu' }
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
                '<li><a href="' + path(root, 'luat-thue-moi.html') + '">Luật Thuế Mới</a></li>' +
                '<li><a href="' + path(root, 'dao-tao.html') + '">Đào Tạo</a></li>' +
	                '<li><a href="' + path(root, 'thu-vien.html') + '">Thư Viện</a></li>' +
	                '<li><a href="' + path(root, 'ban-tin.html') + '">Bản Tin</a></li>' +
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
    if (hamburger && menu) {
      hamburger.addEventListener('click', function () {
        menu.classList.toggle('open');
      });
    }

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
    document.dispatchEvent(new CustomEvent('site-shell-ready'));
  }

  window.KetoanDieuTamShellConfig = config;
  document.addEventListener('DOMContentLoaded', renderShell);
})();
