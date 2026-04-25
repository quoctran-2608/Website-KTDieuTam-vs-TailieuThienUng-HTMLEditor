
    (function () {
      var TAB_LABELS = {
        'tat-ca': 'Tất cả',
        'dang-tuyen': 'Đang tuyển',
        'can-xu-ly': 'Cần xử lý',
        'da-dong': 'Đã đóng'
      };

      var TAB_HINTS = {
        'tat-ca': 'Ưu tiên xử lý tin nháp, sắp hết hạn hoặc đã hết hạn trước.',
        'dang-tuyen': 'Theo dõi các tin đang hiển thị để phản hồi đơn mới trong 24 giờ.',
        'can-xu-ly': 'Nhóm này cần thao tác sớm để tránh gián đoạn tuyển dụng.',
        'da-dong': 'Tin đã hoàn tất tuyển dụng, có thể đăng lại khi cần.'
      };

      var ACTION_LABELS = {
        'gia-han': 'gia hạn',
        'dong-tin': 'đóng',
        'tam-dung': 'tạm dừng'
      };

      function normalizeText(value) {
        return String(value || '')
          .toLowerCase()
          .normalize('NFD')
          .replace(/[\u0300-\u036f]/g, '')
          .replace(/đ/g, 'd')
          .replace(/\s+/g, ' ')
          .trim();
      }

      function parseInteger(value) {
        var parsed = parseInt(value, 10);
        return Number.isNaN(parsed) ? 0 : parsed;
      }

      function parseDateValue(value) {
        var parsed = Date.parse(value || '');
        return Number.isNaN(parsed) ? 0 : parsed;
      }

      function getTabForStatus(statusKey) {
        if (statusKey === 'dang-tuyen' || statusKey === 'sap-het-han') return 'dang-tuyen';
        if (statusKey === 'da-dong') return 'da-dong';
        if (statusKey === 'nhap' || statusKey === 'het-han') return 'can-xu-ly';
        return 'tat-ca';
      }

      function isNeedsAction(statusKey) {
        return statusKey === 'nhap' || statusKey === 'sap-het-han' || statusKey === 'het-han';
      }

      function hasSelectOption(select, value) {
        if (!select || !value) return false;
        return Array.prototype.some.call(select.options, function (option) {
          return option.value === value;
        });
      }

      function initManageJobsPage() {
        var form = document.getElementById('manageJobFilterForm');
        var table = document.getElementById('manageJobTable');
        var tableBody = table ? table.querySelector('tbody') : null;
        var cardList = document.getElementById('manageJobCardList');
        if (!form || !table || !tableBody || !cardList) return;

        var rows = Array.prototype.slice.call(tableBody.querySelectorAll('.jobs-manage-job-row'));
        var tabButtons = Array.prototype.slice.call(document.querySelectorAll('.jobs-manage-tab[data-tab]'));
        var tabInput = document.getElementById('manageJobTabInput');
        var searchInput = document.getElementById('manageJobSearch');
        var statusSelect = document.getElementById('manageJobStatus');
        var sortSelect = document.getElementById('manageJobSort');
        var resetBtn = document.getElementById('manageJobReset');
        var countLabel = document.getElementById('manageJobCount');
        var hintLabel = document.getElementById('manageJobFilterHint');
        var emptyState = document.getElementById('manageJobEmpty');
        var contextNote = document.getElementById('manageJobContextNote');

        var summaryTotal = document.getElementById('manageSummaryTotal');
        var summaryLive = document.getElementById('manageSummaryLive');
        var summaryNeedsAction = document.getElementById('manageSummaryNeedsAction');
        var summaryClosingSoon = document.getElementById('manageSummaryClosingSoon');
        var summaryApplications = document.getElementById('manageSummaryApplications');

        var tabCountAll = document.getElementById('manageTabCountAll');
        var tabCountLive = document.getElementById('manageTabCountLive');
        var tabCountNeedsAction = document.getElementById('manageTabCountNeedsAction');
        var tabCountClosed = document.getElementById('manageTabCountClosed');

        var cardMap = new Map();
        var defaultSort = 'updated-desc';
        var initialParams = typeof URLSearchParams === 'function' ? new URLSearchParams(window.location.search || '') : null;
        var requestedAction = initialParams ? String(initialParams.get('action') || '').trim() : '';
        var requestedJobId = initialParams ? String(initialParams.get('job_id') || '').trim() : '';
        var highlightedJobId = requestedJobId;

        rows.forEach(function (row) {
          row.dataset.searchNormalized = normalizeText(row.dataset.search || row.textContent);
        });

        function getRowKey(row) {
          return row ? String(row.dataset.jobId || '').trim() : '';
        }

        function getRowStatus(row) {
          return row ? String(row.dataset.status || '').trim() : '';
        }

        function getRowTitle(row) {
          var titleLink = row ? row.querySelector('.jobs-manage-title') : null;
          return titleLink ? titleLink.textContent.trim() : '';
        }

        function updateSummaryCards() {
          var totals = {
            total: rows.length,
            live: 0,
            needsAction: 0,
            closingSoon: 0,
            applications: 0
          };

          rows.forEach(function (row) {
            var status = getRowStatus(row);
            var applications = parseInteger(row.dataset.applications);
            totals.applications += applications;
            if (status === 'dang-tuyen' || status === 'sap-het-han') {
              totals.live += 1;
            }
            if (isNeedsAction(status)) {
              totals.needsAction += 1;
            }
            if (status === 'sap-het-han') {
              totals.closingSoon += 1;
            }
          });

          if (summaryTotal) summaryTotal.textContent = String(totals.total);
          if (summaryLive) summaryLive.textContent = String(totals.live);
          if (summaryNeedsAction) summaryNeedsAction.textContent = String(totals.needsAction);
          if (summaryClosingSoon) summaryClosingSoon.textContent = String(totals.closingSoon);
          if (summaryApplications) summaryApplications.textContent = String(totals.applications);
        }

        function updateTabCounts() {
          var counts = {
            'tat-ca': rows.length,
            'dang-tuyen': 0,
            'can-xu-ly': 0,
            'da-dong': 0
          };

          rows.forEach(function (row) {
            var status = getRowStatus(row);
            var tabKey = getTabForStatus(status);
            if (tabKey === 'dang-tuyen') counts['dang-tuyen'] += 1;
            if (tabKey === 'can-xu-ly') counts['can-xu-ly'] += 1;
            if (tabKey === 'da-dong') counts['da-dong'] += 1;
          });

          if (tabCountAll) tabCountAll.textContent = String(counts['tat-ca']);
          if (tabCountLive) tabCountLive.textContent = String(counts['dang-tuyen']);
          if (tabCountNeedsAction) tabCountNeedsAction.textContent = String(counts['can-xu-ly']);
          if (tabCountClosed) tabCountClosed.textContent = String(counts['da-dong']);
        }

        function updateTabButtons() {
          var activeTab = tabInput ? tabInput.value : 'tat-ca';
          tabButtons.forEach(function (button) {
            var isActive = button.dataset.tab === activeTab;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
          });
        }

        function buildContextMessage() {
          if (!contextNote) return;

          if (!requestedAction && !requestedJobId) {
            contextNote.hidden = true;
            contextNote.textContent = '';
            return;
          }

          var targetRow = requestedJobId
            ? rows.find(function (row) { return getRowKey(row) === requestedJobId; })
            : null;
          var targetTitle = targetRow ? getRowTitle(targetRow) : 'tin đã chọn';
          var actionLabel = ACTION_LABELS[requestedAction] || 'cập nhật';
          var message = 'Bạn vừa ' + actionLabel + ' tin "' + targetTitle + '". Kiểm tra lại trạng thái và chọn thao tác tiếp theo bên dưới.';

          contextNote.textContent = message;
          contextNote.hidden = false;
        }

        function buildCard(row) {
          var titleLink = row.querySelector('.jobs-manage-title');
          var titleText = titleLink ? titleLink.textContent.trim() : '';
          var titleHref = titleLink ? titleLink.getAttribute('href') || '#' : '#';
          var metaText = ((row.querySelector('.jobs-manage-meta') || {}).textContent || '').trim();
          var statusPill = (row.querySelector('.jobs-manage-status-cell .jobs-status-pill') || {});
          var statusNote = ((row.querySelector('.jobs-manage-status-cell .jobs-dashboard-note') || {}).textContent || '').trim();
          var deadlineText = ((row.querySelector('.jobs-manage-deadline-text') || {}).textContent || '').trim();
          var deadlineNote = ((row.querySelector('.jobs-manage-deadline-note') || {}).textContent || '').trim();
          var applicationLink = row.querySelector('.jobs-manage-application-link');
          var applicationText = applicationLink ? applicationLink.textContent.trim() : (parseInteger(row.dataset.applications) + ' đơn ứng tuyển');
          var applicationHref = applicationLink ? applicationLink.getAttribute('href') || '#' : '#';
          var actionWrap = row.querySelector('.jobs-manage-actions');
          var actionsHtml = actionWrap ? actionWrap.innerHTML : '';

          var card = document.createElement('article');
          card.className = 'jobs-recruiter-application-card jobs-manage-job-card';
          card.dataset.jobId = getRowKey(row);
          card.dataset.status = getRowStatus(row);
          card.innerHTML = ''
            + '<div class="jobs-recruiter-application-head">'
            +   '<div class="jobs-recruiter-candidate-name">'
            +     '<strong><a href="' + titleHref + '" class="job-source-link jobs-manage-title">' + titleText + '</a></strong>'
            +     '<span>' + metaText + '</span>'
            +   '</div>'
            +   statusPill.outerHTML
            + '</div>'
            + '<div class="jobs-recruiter-application-jobline">'
            +   '<span><strong>Trạng thái:</strong> ' + statusNote + '</span>'
            +   '<span><strong>Hạn nộp:</strong> ' + deadlineText + (deadlineNote ? ' · ' + deadlineNote : '') + '</span>'
            +   '<span><strong>Đơn ứng tuyển:</strong> <a href="' + applicationHref + '" class="job-source-link">' + applicationText + '</a></span>'
            + '</div>'
            + '<div class="jobs-manage-actions jobs-manage-card-actions">' + actionsHtml + '</div>';

          return card;
        }

        function renderCards(matchedRows) {
          cardList.innerHTML = '';
          matchedRows.forEach(function (row) {
            var rowKey = getRowKey(row);
            var card = cardMap.get(rowKey);
            if (!card) {
              card = buildCard(row);
              cardMap.set(rowKey, card);
            }
            card.classList.remove('is-selected');
            cardList.appendChild(card);
          });
        }

        function compareRows(a, b, sortValue) {
          var aTitle = normalizeText(getRowTitle(a));
          var bTitle = normalizeText(getRowTitle(b));
          var aUpdated = parseDateValue(a.dataset.updated);
          var bUpdated = parseDateValue(b.dataset.updated);
          var aDeadline = parseDateValue(a.dataset.deadline);
          var bDeadline = parseDateValue(b.dataset.deadline);
          var aApplications = parseInteger(a.dataset.applications);
          var bApplications = parseInteger(b.dataset.applications);

          if (sortValue === 'deadline-asc') {
            if (aDeadline && bDeadline) return aDeadline - bDeadline || bUpdated - aUpdated;
            if (!aDeadline && bDeadline) return 1;
            if (aDeadline && !bDeadline) return -1;
            return bUpdated - aUpdated;
          }

          if (sortValue === 'applications-desc') {
            return bApplications - aApplications || bUpdated - aUpdated || aTitle.localeCompare(bTitle, 'vi');
          }

          if (sortValue === 'title-asc') {
            return aTitle.localeCompare(bTitle, 'vi') || bUpdated - aUpdated;
          }

          return bUpdated - aUpdated || aTitle.localeCompare(bTitle, 'vi');
        }

        function rowMatchesFilter(row) {
          var activeTab = tabInput ? tabInput.value : 'tat-ca';
          var statusFilter = statusSelect ? String(statusSelect.value || '').trim() : '';
          var query = searchInput ? normalizeText(searchInput.value) : '';
          var rowStatus = getRowStatus(row);
          var rowTab = getTabForStatus(rowStatus);

          if (activeTab !== 'tat-ca' && rowTab !== activeTab) return false;
          if (statusFilter && rowStatus !== statusFilter) return false;
          if (query && row.dataset.searchNormalized.indexOf(query) === -1) return false;
          return true;
        }

        function updateHighlightedState(matchedRows) {
          rows.forEach(function (row) { row.classList.remove('is-selected'); });

          cardList.querySelectorAll('.jobs-manage-job-card').forEach(function (card) {
            card.classList.remove('is-selected');
          });

          if (!highlightedJobId) return;

          var targetRow = matchedRows.find(function (row) {
            return getRowKey(row) === highlightedJobId;
          });

          if (!targetRow) return;

          targetRow.classList.add('is-selected');

          var targetCard = cardMap.get(highlightedJobId);
          if (targetCard) {
            targetCard.classList.add('is-selected');
          }
        }

        function updateUrlState() {
          if (!window.history || typeof window.history.replaceState !== 'function' || !window.URLSearchParams) return;

          var params = new URLSearchParams();
          if (tabInput && tabInput.value && tabInput.value !== 'tat-ca') params.set('tab', tabInput.value);
          if (statusSelect && statusSelect.value) params.set('status', statusSelect.value);
          if (searchInput && searchInput.value.trim()) params.set('q', searchInput.value.trim());
          if (sortSelect && sortSelect.value && sortSelect.value !== defaultSort) params.set('sort', sortSelect.value);
          if (requestedJobId) params.set('job_id', requestedJobId);
          if (requestedAction) params.set('action', requestedAction);

          var query = params.toString();
          var nextUrl = window.location.pathname + (query ? '?' + query : '');
          window.history.replaceState(null, '', nextUrl);
        }

        function applyFilters() {
          var sortValue = sortSelect ? sortSelect.value : defaultSort;
          var matchedRows = rows.filter(rowMatchesFilter).sort(function (a, b) {
            return compareRows(a, b, sortValue);
          });

          tableBody.innerHTML = '';
          matchedRows.forEach(function (row) {
            tableBody.appendChild(row);
          });

          renderCards(matchedRows);
          updateHighlightedState(matchedRows);

          if (countLabel) {
            countLabel.textContent = matchedRows.length + ' tin đang hiển thị';
          }

          if (hintLabel) {
            hintLabel.textContent = TAB_HINTS[tabInput.value] || TAB_HINTS['tat-ca'];
          }

          if (emptyState) {
            emptyState.hidden = matchedRows.length > 0;
          }

          updateTabButtons();
          updateUrlState();
        }

        function setActiveTab(tabKey) {
          var nextTab = TAB_LABELS[tabKey] ? tabKey : 'tat-ca';
          if (tabInput) tabInput.value = nextTab;
        }

        function hydrateFromUrl() {
          if (!initialParams) return;

          var tabFromUrl = String(initialParams.get('tab') || '').trim();
          var queryFromUrl = String(initialParams.get('q') || '').trim();
          var statusFromUrl = String(initialParams.get('status') || '').trim();
          var sortFromUrl = String(initialParams.get('sort') || '').trim();

          if (TAB_LABELS[tabFromUrl]) {
            setActiveTab(tabFromUrl);
          } else if (requestedJobId) {
            setActiveTab('can-xu-ly');
          }

          if (searchInput && queryFromUrl) {
            searchInput.value = queryFromUrl;
          }

          if (statusSelect && hasSelectOption(statusSelect, statusFromUrl)) {
            statusSelect.value = statusFromUrl;
          }

          if (sortSelect && hasSelectOption(sortSelect, sortFromUrl)) {
            sortSelect.value = sortFromUrl;
          }
        }

        tabButtons.forEach(function (button) {
          button.addEventListener('click', function () {
            var tabKey = String(button.dataset.tab || '').trim();
            setActiveTab(tabKey);
            applyFilters();
          });
        });

        if (searchInput) {
          searchInput.addEventListener('input', applyFilters);
        }

        if (statusSelect) {
          statusSelect.addEventListener('change', applyFilters);
        }

        if (sortSelect) {
          sortSelect.addEventListener('change', applyFilters);
        }

        form.addEventListener('submit', function (event) {
          event.preventDefault();
          applyFilters();
        });

        if (resetBtn) {
          resetBtn.addEventListener('click', function () {
            if (searchInput) searchInput.value = '';
            if (statusSelect) statusSelect.value = '';
            if (sortSelect) sortSelect.value = defaultSort;
            setActiveTab('tat-ca');
            highlightedJobId = '';
            requestedJobId = '';
            requestedAction = '';
            buildContextMessage();
            applyFilters();
          });
        }

        hydrateFromUrl();
        updateSummaryCards();
        updateTabCounts();
        buildContextMessage();
        applyFilters();
      }

      document.addEventListener('DOMContentLoaded', initManageJobsPage);
    })();
  