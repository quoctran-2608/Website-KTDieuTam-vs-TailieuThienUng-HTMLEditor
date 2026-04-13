document.addEventListener('DOMContentLoaded', function () {
  if (window.AOS && typeof window.AOS.init === 'function') {
    window.AOS.init({ once: true, offset: 80 });
  }

  document.querySelectorAll('.faq-question').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var item = this.closest('.faq-item');
      var wasOpen = item.classList.contains('is-open');
      document.querySelectorAll('.faq-item').forEach(function (el) {
        el.classList.remove('is-open');
      });
      if (!wasOpen) item.classList.add('is-open');
    });
  });

  var contactForm = document.getElementById('contactForm');
  if (contactForm) {
    contactForm.addEventListener('submit', function (event) {
      event.preventDefault();
      var nameField = this.querySelector('input[type="text"]');
      var name = nameField ? nameField.value.trim() : '';
      var userName = name || 'bạn';
      window.alert(
        'Cảm ơn đại diện ' +
          userName +
          ' đã để lại thông tin.\nHệ thống đang chuyển hướng tới Zalo để chuyên gia tư vấn ngay cho bạn.'
      );
      window.open('https://zalo.me/0777315188', '_blank', 'noopener');
      this.reset();
    });
  }
});
