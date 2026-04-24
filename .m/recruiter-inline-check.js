(function () {
      var STATUS_LABELS = {
        'moi_nop': 'Mới nộp',
        'dang_xem': 'Đang xem hồ sơ',
        'moi_phong_van': 'Đề nghị phỏng vấn',
        'da_lien_he': 'Đã liên hệ',
        'can_bo_sung': 'Cần bổ sung thông tin',
        'tu_choi': 'Không phù hợp',
        'trung_tuyen': 'Trúng tuyển'
      };

      var STATUS_PILLS = {
        'moi_nop': 'is-saved',
        'dang_xem': 'is-reviewing',
        'moi_phong_van': 'is-active',
        'da_lien_he': 'is-active',
        'can_bo_sung': 'is-muted',
        'tu_choi': 'is-muted',
        'trung_tuyen': 'is-active'
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

      function initRecruiterCandidateFilter() {
        var form = document.getElementById('recruiterCandidateFilterForm');
        var table = document.getElementById('recruiterCandidateTable');
        var list = document.getElementById('recruiterCandidateList');
        if (!form || !table || !list) return;

        var rows = Array.prototype.slice.call(table.querySelectorAll('.jobs-recruiter-candidate-row'));
        var searchInput = document.getElementById('recruiterCandidateSearch');
        var jobSelect = document.getElementById('recruiterCandidateJob');
        var statusSelect = document.getElementById('recruiterCandidateStatus');
        var sortSelect = document.getElementById('recruiterCandidateSort');
        var resetBtn = document.getElementById('recruiterCandidateReset');
        var countLabel = document.getElementById('recruiterCandidateCount');
        var emptyState = document.getElementById('recruiterCandidateEmpty');
        var shortlistCount = document.getElementById('recruiterShortlistCount');
        var shortlistList = document.getElementById('recruiterShortlistList');
        var activityFeed = document.getElementById('recruiterActivityFeed');
        var noteDialog = document.getElementById('recruiterNoteDialog');
        var noteTitle = document.getElementById('recruiterNoteTitle');
        var noteInput = document.getElementById('recruiterNoteInput');
        var noteSave = document.getElementById('recruiterNoteSave');
        var noteCancel = document.getElementById('recruiterNoteCancel');
        var focusHint = document.getElementById('recruiterFocusHint');
        var focusPanel = document.getElementById('recruiterFocusPanel');
        var focusBackdrop = document.getElementById('recruiterFocusBackdrop');
        var focusClose = document.getElementById('recruiterFocusClose');
        var focusCard = document.getElementById('recruiterFocusCard');
        var focusApplicationId = document.getElementById('recruiterFocusApplicationId');
        var focusName = document.getElementById('recruiterFocusName');
        var focusMeta = document.getElementById('recruiterFocusMeta');
        var focusJob = document.getElementById('recruiterFocusJob');
        var focusProfileLink = document.getElementById('recruiterFocusProfileLink');
        var focusStatus = document.getElementById('recruiterFocusStatus');
        var focusShortlistBtn = document.getElementById('recruiterFocusShortlistBtn');
        var focusNoteBtn = document.getElementById('recruiterFocusNoteBtn');
        var focusTip = document.getElementById('recruiterFocusTip');

	        var focusProfileDefaultLabel = focusProfileLink ? focusProfileLink.textContent.trim() : 'Mở hồ sơ';
	        var defaultSortValue = sortSelect ? sortSelect.value : 'updated-desc';
	        var mediaQueryList = typeof window.matchMedia === 'function' ? window.matchMedia('(max-width: 1100px)') : null;
	        var initialParams = typeof URLSearchParams === 'function' ? new URLSearchParams(window.location.search || '') : null;
	        var requestedApplicationId = initialParams ? initialParams.get('application_id') || '' : '';
	        var shortlistStore = new Set();
	        var noteStore = {};
	        var currentNoteApplicationId = null;
	        var currentFocusedRow = null;
	        var cardMap = new Map();

        function getRowKey(row) {
          return row ? String(row.dataset.applicationId || row.dataset.candidateId || '') : '';
        }

        function getRowCells(row) {
          return row ? row.querySelectorAll('td') : [];
        }

        function getRowName(row) {
          return ((row.querySelector('.jobs-recruiter-candidate-name strong') || {}).textContent || '').trim();
        }

        function getRowLocation(row) {
          return ((row.querySelector('.jobs-recruiter-candidate-name span') || {}).textContent || '').trim();
        }

        function getRowRole(row) {
          return String(row.dataset.role || '').trim();
        }

        function getRowExperience(row) {
          return String(row.dataset.experience || '').trim();
        }

        function getRowStatusKey(row) {
          return String(row.dataset.status || 'dang_xem').trim();
        }

        function getRowStatusLabel(row) {
          return STATUS_LABELS[getRowStatusKey(row)] || 'Đang xem hồ sơ';
        }

        function getRowStatusClass(row) {
          return STATUS_PILLS[getRowStatusKey(row)] || 'is-reviewing';
        }

        function getRowUpdated(row) {
          var cells = getRowCells(row);
          return cells[6] ? cells[6].textContent.trim() : '';
        }

        function getRowJobLink(row) {
          var cells = getRowCells(row);
          return cells[2] ? cells[2].querySelector('a.job-source-link') : null;
        }

        function getRowJobText(row) {
          var jobLink = getRowJobLink(row);
          return jobLink ? jobLink.textContent.trim() : '';
        }

        function getRowJobHref(row) {
          var jobLink = getRowJobLink(row);
          return jobLink ? jobLink.getAttribute('href') || '#' : '#';
        }

        function getRowProfileHref(row) {
          return String(row.dataset.candidateProfile || '#').trim();
        }

        function getRowTip(row) {
          return ((row.querySelector('.jobs-recruiter-action-tip') || {}).textContent || '').trim();
        }

	        function compareRows(a, b, sortValue) {
	          var aUpdated = Date.parse(a.dataset.updatedDate || '') || 0;
	          var bUpdated = Date.parse(b.dataset.updatedDate || '') || 0;
          var aName = normalizeText(getRowName(a));
          var bName = normalizeText(getRowName(b));
          if (sortValue === 'name-asc') {
            return aName.localeCompare(bName, 'vi');
	          }
	          return bUpdated - aUpdated || aName.localeCompare(bName, 'vi');
	        }

	        function hasSelectOption(select, value) {
	          if (!select || !value) return false;
	          return Array.prototype.some.call(select.options, function (option) {
	            return option.value === value;
	          });
	        }

	        function setSelectValue(select, value, fallbackValue) {
	          if (!select) return;
	          if (value && hasSelectOption(select, value)) {
	            select.value = value;
	            return;
	          }
	          select.value = fallbackValue || '';
	        }

	        function hydrateFiltersFromUrl() {
	          if (!initialParams) return;
	          if (searchInput) {
	            searchInput.value = initialParams.get('q') || '';
	          }
	          setSelectValue(jobSelect, initialParams.get('job_id') || '', '');
	          setSelectValue(statusSelect, initialParams.get('status') || '', '');
	          setSelectValue(sortSelect, initialParams.get('sort') || '', defaultSortValue);
	        }

	        function shouldScrollToFocus() {
	          return mediaQueryList ? mediaQueryList.matches : window.innerWidth <= 1100;
	        }

	        function openFocusPanel() {
	          if (!focusPanel) return;
	          focusPanel.hidden = false;
	          focusPanel.classList.add('is-open');
	          if (focusBackdrop) {
	            focusBackdrop.hidden = false;
	          }
	          document.body.classList.add('is-recruiter-focus-open');
	        }

	        function closeFocusPanel() {
	          if (!focusPanel) return;
	          focusPanel.hidden = true;
	          focusPanel.classList.remove('is-open');
	          if (focusBackdrop) {
	            focusBackdrop.hidden = true;
	          }
	          document.body.classList.remove('is-recruiter-focus-open');
	        }

	        function scrollFocusCardIntoView() {
	          if (!focusCard) return;
	          openFocusPanel();
	          if (shouldScrollToFocus() && typeof focusCard.scrollIntoView === 'function') {
	            focusCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
	          }
	        }

	        function updateUrlState() {
	          if (typeof URLSearchParams !== 'function' || !window.history || typeof window.history.replaceState !== 'function') return;
	          var params = new URLSearchParams();
	          params.set('view', 'theo-tin');
	          if (searchInput && searchInput.value.trim()) {
	            params.set('q', searchInput.value.trim());
	          }
	          if (jobSelect && jobSelect.value) {
	            params.set('job_id', jobSelect.value);
	          }
	          if (statusSelect && statusSelect.value) {
	            params.set('status', statusSelect.value);
	          }
	          if (sortSelect && sortSelect.value && sortSelect.value !== defaultSortValue) {
	            params.set('sort', sortSelect.value);
	          }
	          if (currentFocusedRow && currentFocusedRow.dataset.applicationId) {
	            params.set('application_id', currentFocusedRow.dataset.applicationId);
	          }
	          var query = params.toString();
	          var nextUrl = window.location.pathname + (query ? '?' + query : '');
	          window.history.replaceState(null, '', nextUrl);
	        }

	        function pushActivity(message) {
	          if (!activityFeed) return;
          var item = document.createElement('li');
          item.textContent = message;
          activityFeed.prepend(item);
          var items = activityFeed.querySelectorAll('li');
          if (items.length > 8) {
            items[items.length - 1].remove();
          }
        }

        function syncShortlistPanel() {
          if (!shortlistCount || !shortlistList) return;
          shortlistCount.textContent = String(shortlistStore.size);

          if (!shortlistStore.size) {
            shortlistList.innerHTML = '<li class="jobs-recruiter-empty-note">Chưa có đơn nào trong danh sách ưu tiên.</li>';
            return;
          }

          var items = [];
          rows.forEach(function (row) {
            var applicationId = row.dataset.applicationId || '';
            if (!shortlistStore.has(applicationId)) return;
            items.push('<li><a href="' + getRowProfileHref(row) + '">' + getRowName(row) + '</a><span>' + getRowRole(row) + ' · ' + (row.dataset.applicationId || '') + '</span></li>');
          });
	          shortlistList.innerHTML = items.join('');
	        }

	        function setFocusAvailability(hasSelection) {
	          if (focusCard) {
	            focusCard.classList.toggle('is-empty', !hasSelection);
	          }
	          if (focusStatus) {
	            focusStatus.disabled = !hasSelection;
	          }
	          if (focusShortlistBtn) {
	            focusShortlistBtn.disabled = !hasSelection;
	          }
	          if (focusNoteBtn) {
	            focusNoteBtn.disabled = !hasSelection;
	          }
	          if (focusProfileLink) {
	            focusProfileLink.classList.toggle('is-disabled', !hasSelection);
	            focusProfileLink.setAttribute('aria-disabled', hasSelection ? 'false' : 'true');
	            if (hasSelection) {
	              focusProfileLink.removeAttribute('tabindex');
	              focusProfileLink.textContent = focusProfileDefaultLabel;
	              return;
	            }
	            focusProfileLink.setAttribute('tabindex', '-1');
	            focusProfileLink.textContent = 'Chọn đơn để mở hồ sơ';
	          }
	        }

	        function syncFocusShortlistState() {
	          if (!focusShortlistBtn || !currentFocusedRow) return;
          var applicationId = currentFocusedRow.dataset.applicationId || '';
          var active = shortlistStore.has(applicationId);
          focusShortlistBtn.classList.toggle('is-active', active);
          focusShortlistBtn.setAttribute('aria-pressed', active ? 'true' : 'false');
          focusShortlistBtn.textContent = active ? 'Bỏ ưu tiên' : 'Đánh dấu ưu tiên';

          var card = cardMap.get(getRowKey(currentFocusedRow));
          if (card) {
            card.classList.toggle('is-priority', active);
          }
        }

        function openNoteDialog(applicationId, candidateName) {
          if (!noteDialog || !noteInput || !noteTitle) return;
          currentNoteApplicationId = applicationId;
          noteTitle.textContent = 'Ghi chú nội bộ: ' + candidateName + ' · ' + applicationId;
          noteInput.value = noteStore[applicationId] || '';
          noteDialog.hidden = false;
          document.body.classList.add('is-note-dialog-open');
          window.setTimeout(function () { noteInput.focus(); }, 10);
        }

        function closeNoteDialog() {
          if (!noteDialog) return;
          noteDialog.hidden = true;
          document.body.classList.remove('is-note-dialog-open');
          currentNoteApplicationId = null;
        }

        function updateStatusPill(row, statusKey) {
          row.dataset.status = statusKey;
          var hiddenSelect = row.querySelector('.jobs-recruiter-status-select');
          if (hiddenSelect) {
            hiddenSelect.value = statusKey;
          }

          var card = cardMap.get(getRowKey(row));
          if (card) {
            var pill = card.querySelector('.jobs-recruiter-status-pill');
            if (pill) {
              pill.className = 'jobs-status-pill jobs-recruiter-status-pill ' + (STATUS_PILLS[statusKey] || 'is-reviewing');
              pill.textContent = STATUS_LABELS[statusKey] || 'Đang xem hồ sơ';
            }
          }
        }

        function buildCard(row) {
          var card = document.createElement('article');
          card.className = 'jobs-recruiter-candidate-row jobs-recruiter-application-card';
          Array.prototype.forEach.call(row.attributes, function (attr) {
            if (attr.name.indexOf('data-') === 0) {
              card.setAttribute(attr.name, attr.value);
            }
          });

          card.innerHTML = ''
            + '<div class="jobs-recruiter-application-head">'
            +   '<div class="jobs-recruiter-application-id">'
            +     '<span class="jobs-status-pill jobs-recruiter-application-code is-saved">' + (row.dataset.applicationId || '') + '</span>'
            +     '<span class="jobs-dashboard-note">' + getRowUpdated(row) + '</span>'
            +   '</div>'
            +   '<span class="jobs-status-pill jobs-recruiter-status-pill ' + getRowStatusClass(row) + '">' + getRowStatusLabel(row) + '</span>'
            + '</div>'
            + '<div class="jobs-recruiter-application-body">'
            +   '<div class="jobs-recruiter-candidate-name">'
            +     '<strong>' + getRowName(row) + '</strong>'
            +     '<span>' + getRowRole(row) + ' · ' + getRowExperience(row) + '</span>'
            +     '<span>' + getRowLocation(row) + '</span>'
            +   '</div>'
            +   '<div class="jobs-recruiter-application-job">'
            +     '<span class="jobs-dashboard-note">Tin tuyển dụng</span>'
            +     '<a href="' + getRowJobHref(row) + '" class="job-source-link">' + getRowJobText(row) + '</a>'
            +   '</div>'
            + '</div>'
            + '<div class="jobs-recruiter-application-actions">'
            +   '<a href="' + getRowProfileHref(row) + '" class="btn-outline-brown">Mở hồ sơ</a>'
	            +   '<button type="button" class="btn-primary-orange jobs-recruiter-manage-btn">Xử lý nhanh</button>'
            + '</div>'
            + '<p class="jobs-recruiter-action-tip">' + getRowTip(row) + '</p>';

	          card.addEventListener('click', function (event) {
	            if (event.target.closest('a, button')) return;
	            setFocusRow(row, { reveal: true });
	            scrollFocusCardIntoView();
	          });

	          var manageBtn = card.querySelector('.jobs-recruiter-manage-btn');
	          if (manageBtn) {
	            manageBtn.addEventListener('click', function () {
	              setFocusRow(row, { reveal: true });
	              scrollFocusCardIntoView();
	              if (focusStatus) {
	                focusStatus.focus();
	              }
            });
          }

          cardMap.set(getRowKey(row), card);
          return card;
        }

        function renderCards(matchedRows) {
          list.innerHTML = '';
          matchedRows.forEach(function (row) {
            var key = getRowKey(row);
            var card = cardMap.get(key) || buildCard(row);
            list.appendChild(card);
          });
        }

	        function clearFocusRow() {
	          currentFocusedRow = null;
	          requestedApplicationId = '';
	          list.querySelectorAll('.jobs-recruiter-application-card').forEach(function (card) {
	            card.classList.remove('is-selected');
	          });
	          if (focusHint) focusHint.textContent = 'Chọn một đơn trong danh sách để cập nhật nhanh.';
	          if (focusApplicationId) focusApplicationId.textContent = 'Chưa chọn đơn';
	          if (focusName) focusName.textContent = 'Chưa có đơn được chọn';
	          if (focusMeta) focusMeta.textContent = 'Danh sách đang không có đơn khả dụng hoặc bạn chưa chọn hồ sơ nào.';
	          if (focusJob) focusJob.textContent = 'Tin tuyển dụng: Chưa chọn';
	          if (focusProfileLink) focusProfileLink.setAttribute('href', '#');
	          if (focusTip) focusTip.textContent = 'Khối này sẽ sáng lên ngay khi có một đơn phù hợp trong danh sách.';
	          if (focusStatus) focusStatus.value = 'dang_xem';
	          if (focusShortlistBtn) {
	            focusShortlistBtn.classList.remove('is-active');
            focusShortlistBtn.setAttribute('aria-pressed', 'false');
            focusShortlistBtn.textContent = 'Đánh dấu ưu tiên';
          }
	          if (focusNoteBtn) {
	            focusNoteBtn.classList.remove('is-active');
	          }
	          setFocusAvailability(false);
	          updateUrlState();
	          closeFocusPanel();
	        }

	        function setFocusRow(row, options) {
	          var revealPanel = !!(options && options.reveal);
	          if (!row) {
	            clearFocusRow();
	            return;
          }

	          currentFocusedRow = row;
	          requestedApplicationId = row.dataset.applicationId || '';
	          var currentKey = getRowKey(row);

	          list.querySelectorAll('.jobs-recruiter-application-card').forEach(function (card) {
	            card.classList.toggle('is-selected', getRowKey(card) === currentKey);
	          });

	          setFocusAvailability(true);
	          if (focusHint) focusHint.textContent = 'Đang xử lý ' + (row.dataset.applicationId || 'đơn đã chọn') + '.';
	          if (focusApplicationId) focusApplicationId.textContent = row.dataset.applicationId || '';
	          if (focusName) focusName.textContent = getRowName(row);
          if (focusMeta) focusMeta.textContent = getRowRole(row) + ' · ' + getRowExperience(row) + ' · ' + getRowLocation(row);
          if (focusJob) focusJob.textContent = 'Tin tuyển dụng: ' + getRowJobText(row);
          if (focusProfileLink) focusProfileLink.setAttribute('href', getRowProfileHref(row));
          if (focusStatus) focusStatus.value = getRowStatusKey(row);
          if (focusTip) focusTip.textContent = getRowTip(row);
	          if (focusNoteBtn) {
	            focusNoteBtn.classList.toggle('is-active', !!noteStore[row.dataset.applicationId || '']);
	          }

	          syncFocusShortlistState();
	          updateUrlState();
	          if (revealPanel) {
	            openFocusPanel();
	          }
	        }

        function applyFilters() {
          var query = normalizeText(searchInput ? searchInput.value : '');
          var jobId = normalizeText(jobSelect ? jobSelect.value : '');
          var status = normalizeText(statusSelect ? statusSelect.value : '');
          var sortValue = sortSelect ? sortSelect.value : 'updated-desc';

          var matched = rows.filter(function (row) {
            var searchBlob = normalizeText(row.dataset.search || row.textContent || '');
            var rowStatus = normalizeText(row.dataset.status || '');
            var rowJobId = normalizeText(row.dataset.jobId || '');
            var matchesQuery = !query || searchBlob.indexOf(query) !== -1;
            var matchesJob = !jobId || rowJobId === jobId;
            var matchesStatus = !status || rowStatus === status;
            return matchesQuery && matchesJob && matchesStatus;
          });

          matched.sort(function (a, b) { return compareRows(a, b, sortValue); });
          renderCards(matched);

          if (countLabel) {
            countLabel.textContent = matched.length + ' đơn đang hiển thị';
          }
          if (emptyState) {
            emptyState.hidden = matched.length !== 0;
          }

	          if (!matched.length) {
	            clearFocusRow();
	            return;
	          }

	          if (currentFocusedRow && matched.indexOf(currentFocusedRow) !== -1) {
	            setFocusRow(currentFocusedRow, { reveal: focusPanel && !focusPanel.hidden });
	            return;
	          }

	          if (requestedApplicationId) {
	            var requestedRow = matched.find(function (row) {
	              return (row.dataset.applicationId || '') === requestedApplicationId;
	            });
	            if (requestedRow) {
	              setFocusRow(requestedRow, { reveal: false });
	              return;
	            }
	          }

	          setFocusRow(matched[0], { reveal: false });
	        }

	        if (form) {
	          form.addEventListener('input', applyFilters);
	          form.addEventListener('change', applyFilters);
	          form.addEventListener('submit', function (event) {
	            event.preventDefault();
	            applyFilters();
	          });
	        }

	        if (resetBtn) {
	          resetBtn.addEventListener('click', function () {
	            requestedApplicationId = '';
	            form.reset();
	            if (sortSelect) {
	              sortSelect.value = defaultSortValue;
	            }
	            applyFilters();
	          });
	        }

        if (focusShortlistBtn) {
          focusShortlistBtn.addEventListener('click', function () {
            if (!currentFocusedRow) return;
            var candidateName = currentFocusedRow.dataset.candidateName || 'Ứng viên';
            var applicationId = currentFocusedRow.dataset.applicationId || '';
            if (shortlistStore.has(applicationId)) {
              shortlistStore.delete(applicationId);
              pushActivity('Đã bỏ ưu tiên cho đơn ' + applicationId + ' của ' + candidateName + '.');
            } else {
              shortlistStore.add(applicationId);
              pushActivity('Đã thêm đơn ' + applicationId + ' của ' + candidateName + ' vào danh sách ưu tiên.');
            }
            syncShortlistPanel();
            syncFocusShortlistState();
          });
        }

        if (focusNoteBtn) {
          focusNoteBtn.addEventListener('click', function () {
            if (!currentFocusedRow) return;
            openNoteDialog(currentFocusedRow.dataset.applicationId || '', currentFocusedRow.dataset.candidateName || 'Ứng viên');
          });
        }

        if (focusStatus) {
          focusStatus.addEventListener('change', function () {
            if (!currentFocusedRow) return;
            var nextStatus = focusStatus.value || 'dang_xem';
            var applicationId = currentFocusedRow.dataset.applicationId || '';
            var candidateName = currentFocusedRow.dataset.candidateName || 'Ứng viên';
            updateStatusPill(currentFocusedRow, nextStatus);
            pushActivity('Đã cập nhật trạng thái đơn ' + applicationId + ' của ' + candidateName + ' thành ' + (STATUS_LABELS[nextStatus] || 'Đang xem hồ sơ') + '.');
            applyFilters();
          });
        }

        if (noteSave && noteInput) {
          noteSave.addEventListener('click', function () {
            if (!currentNoteApplicationId) return;
            var value = noteInput.value.trim();
            if (!value) {
              delete noteStore[currentNoteApplicationId];
            } else {
              noteStore[currentNoteApplicationId] = value;
            }

            var row = rows.find(function (item) { return (item.dataset.applicationId || '') === currentNoteApplicationId; });
            if (row) {
              var candidateName = row.dataset.candidateName || 'Ứng viên';
              pushActivity(value ? ('Đã lưu ghi chú nội bộ cho ' + candidateName + '.') : ('Đã xóa ghi chú nội bộ của ' + candidateName + '.'));
              if (currentFocusedRow && currentFocusedRow.dataset.applicationId === currentNoteApplicationId && focusNoteBtn) {
                focusNoteBtn.classList.toggle('is-active', !!value);
              }
            }
            closeNoteDialog();
          });
        }

        if (noteCancel) {
          noteCancel.addEventListener('click', closeNoteDialog);
        }

        if (noteDialog) {
          noteDialog.addEventListener('click', function (event) {
            var dismissTarget = event.target.closest('[data-note-dismiss="backdrop"]');
            if (dismissTarget) {
              closeNoteDialog();
            }
          });
        }

	        document.addEventListener('keydown', function (event) {
	          if (event.key === 'Escape' && noteDialog && !noteDialog.hidden) {
	            closeNoteDialog();
	            return;
	          }
	          if (event.key === 'Escape' && focusPanel && !focusPanel.hidden) {
	            closeFocusPanel();
	          }
	        });

	        if (focusClose) {
	          focusClose.addEventListener('click', closeFocusPanel);
	        }

	        if (focusBackdrop) {
	          focusBackdrop.addEventListener('click', closeFocusPanel);
	        }

	        if (focusProfileLink) {
	          focusProfileLink.addEventListener('click', function (event) {
	            if (focusProfileLink.classList.contains('is-disabled')) {
	              event.preventDefault();
	            }
	          });
	        }

	        hydrateFiltersFromUrl();
	        syncShortlistPanel();
	        applyFilters();
	      }

      document.addEventListener('DOMContentLoaded', initRecruiterCandidateFilter);
    })();