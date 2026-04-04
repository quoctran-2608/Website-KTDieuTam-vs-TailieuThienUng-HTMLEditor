(function () {
  if (window.KetoanDieuTamArticleLayoutLoaded) return;
  var root = (document.body && document.body.dataset && document.body.dataset.root) || '';
  var script = document.createElement('script');
  script.src = root + 'article-layout.js';
  document.head.appendChild(script);
})();
