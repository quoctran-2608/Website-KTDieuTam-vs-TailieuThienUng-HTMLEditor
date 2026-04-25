(function () {
  'use strict';

  var data = window.VN_LOCATION_DATA || { provinces: [] };
  var provinces = Array.isArray(data.provinces) ? data.provinces : [];

  function normalizeText(value) {
    return String(value || '')
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .replace(/đ/g, 'd')
      .replace(/[^a-z0-9]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function hasAlias(text, alias) {
    var haystack = normalizeText(text);
    var needle = normalizeText(alias);
    if (!needle) return false;
    return new RegExp('(^| )' + needle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '($| )').test(haystack);
  }

  function option(label, value) {
    var node = document.createElement('option');
    node.value = value || '';
    node.textContent = label;
    return node;
  }

  function findProvince(key) {
    return provinces.find(function (province) {
      return String(province.key) === String(key);
    }) || null;
  }

  function findArea(province, key) {
    if (!province || !Array.isArray(province.areas)) return null;
    return province.areas.find(function (area) {
      return String(area.key) === String(key);
    }) || null;
  }

  function provinceLabel(province) {
    return province ? (province.name || province.fullName || '') : '';
  }

  function areaLabel(area) {
    return area ? (area.displayName || area.name || area.fullName || '') : '';
  }

  function provinceAliases(province) {
    return [province.name, province.fullName].concat(province.aliases || []).filter(Boolean);
  }

  function areaAliases(area) {
    return [area.name, area.fullName, area.displayName].concat(area.aliases || []).filter(Boolean);
  }

  function inferLocation(text) {
    var folded = normalizeText(text);
    if (!folded) return null;
    var best = null;

    provinces.forEach(function (province) {
      var provinceScore = 0;
      provinceAliases(province).forEach(function (alias) {
        var normalizedAlias = normalizeText(alias);
        if (normalizedAlias && hasAlias(folded, normalizedAlias)) {
          provinceScore = Math.max(provinceScore, normalizedAlias.length + 35);
        }
      });

      (province.areas || []).forEach(function (area) {
        var areaScore = 0;
        areaAliases(area).forEach(function (alias) {
          var normalizedAlias = normalizeText(alias);
          if (!normalizedAlias || !hasAlias(folded, normalizedAlias)) return;
          var score = normalizedAlias.length;
          if (normalizeText(area.fullName) === normalizedAlias || normalizeText(area.name) === normalizedAlias) {
            score += 60;
          }
          areaScore = Math.max(areaScore, score);
        });
        var totalScore = provinceScore + areaScore;
        if (totalScore > 0 && (!best || totalScore > best.score)) {
          best = { province: province, area: area, score: totalScore };
        }
      });

      if (provinceScore > 0 && (!best || provinceScore > best.score)) {
        best = { province: province, area: null, score: provinceScore };
      }
    });

    return best;
  }

  function buildDisplay(province, area, address) {
    var parts = [];
    var addressText = String(address || '').trim();
    if (addressText) parts.push(addressText);
    if (area) parts.push(areaLabel(area));
    if (province) parts.push(provinceLabel(province));
    return parts.join(', ');
  }

  function getLabel(select) {
    if (!select || select.selectedIndex < 0) return '';
    return select.options[select.selectedIndex].textContent || '';
  }

  function fillProvinceSelect(select) {
    if (!select || select.dataset.locationReady === 'true') return;
    var mode = select.dataset.locationMode || 'input';
    var placeholder = select.dataset.placeholder || (mode === 'filter' ? 'Tất cả tỉnh/thành' : 'Chọn tỉnh/thành phố');
    select.innerHTML = '';
    select.appendChild(option(placeholder, ''));
    provinces.forEach(function (province) {
      select.appendChild(option(provinceLabel(province), province.key));
    });
    select.dataset.locationReady = 'true';
  }

  function fillAreaSelect(areaSelect, provinceKey, selectedAreaKey) {
    if (!areaSelect) return;
    var mode = areaSelect.dataset.locationMode || 'input';
    var placeholder = areaSelect.dataset.placeholder || (mode === 'filter' ? 'Tất cả quận/huyện/khu vực' : 'Chọn quận/huyện/khu vực');
    var province = findProvince(provinceKey);
    areaSelect.innerHTML = '';
    areaSelect.appendChild(option(placeholder, ''));
    (province ? province.areas || [] : []).forEach(function (area) {
      areaSelect.appendChild(option(areaLabel(area), area.key));
    });
    areaSelect.disabled = !province;
    if (selectedAreaKey && findArea(province, selectedAreaKey)) {
      areaSelect.value = selectedAreaKey;
    }
  }

  function updateHiddenTargets(provinceSelect, areaSelect) {
    var province = findProvince(provinceSelect && provinceSelect.value);
    var area = findArea(province, areaSelect && areaSelect.value);
    var displayTarget = document.getElementById(provinceSelect.dataset.locationDisplayTarget || '');
    var provinceNameTarget = document.getElementById(provinceSelect.dataset.locationProvinceNameTarget || '');
    var areaNameTarget = document.getElementById(provinceSelect.dataset.locationAreaNameTarget || '');
    var provinceKeyTarget = document.getElementById(provinceSelect.dataset.locationProvinceKeyTarget || '');
    var areaKeyTarget = document.getElementById(provinceSelect.dataset.locationAreaKeyTarget || '');
    var addressTarget = document.getElementById(provinceSelect.dataset.locationAddressTarget || '');
    var address = addressTarget ? addressTarget.value : '';

    if (displayTarget) displayTarget.value = buildDisplay(province, area, address);
    if (provinceNameTarget) provinceNameTarget.value = provinceLabel(province);
    if (areaNameTarget) areaNameTarget.value = areaLabel(area);
    if (provinceKeyTarget) provinceKeyTarget.value = province ? province.key : '';
    if (areaKeyTarget) areaKeyTarget.value = area ? area.key : '';
  }

  function initPair(provinceSelect) {
    if (!provinceSelect) return;
    if (provinceSelect.dataset.locationPairReady === 'true') return;
    provinceSelect.dataset.locationPairReady = 'true';
    fillProvinceSelect(provinceSelect);

    var areaSelect = document.getElementById(provinceSelect.dataset.locationTargetArea || '');
    var source = document.getElementById(provinceSelect.dataset.locationResolveSource || '');
    var initialText = source ? source.value : '';
    var inferred = inferLocation(initialText);
    var initialProvince = provinceSelect.dataset.locationInitial || provinceSelect.value || (inferred && inferred.province ? inferred.province.key : '');
    var initialArea = areaSelect ? (areaSelect.dataset.locationInitial || areaSelect.value || (inferred && inferred.area ? inferred.area.key : '')) : '';

    if (initialProvince && findProvince(initialProvince)) {
      provinceSelect.value = initialProvince;
    }
    fillAreaSelect(areaSelect, provinceSelect.value, initialArea);
    updateHiddenTargets(provinceSelect, areaSelect);

    provinceSelect.addEventListener('change', function () {
      fillAreaSelect(areaSelect, provinceSelect.value, '');
      updateHiddenTargets(provinceSelect, areaSelect);
      if (areaSelect) areaSelect.dispatchEvent(new Event('change', { bubbles: true }));
    });

    if (areaSelect) {
      areaSelect.addEventListener('change', function () {
        updateHiddenTargets(provinceSelect, areaSelect);
      });
    }

    var addressTarget = document.getElementById(provinceSelect.dataset.locationAddressTarget || '');
    if (addressTarget) {
      addressTarget.addEventListener('input', function () {
        updateHiddenTargets(provinceSelect, areaSelect);
      });
    }
  }

  function init(root) {
    Array.prototype.forEach.call((root || document).querySelectorAll('[data-location-province-select]'), initPair);
  }

  window.JobLocationPicker = {
    data: data,
    init: init,
    inferLocation: inferLocation,
    normalizeText: normalizeText,
    findProvince: findProvince,
    findArea: findArea,
    getLabel: getLabel,
    buildDisplay: buildDisplay
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { init(document); });
  } else {
    init(document);
  }
})();
