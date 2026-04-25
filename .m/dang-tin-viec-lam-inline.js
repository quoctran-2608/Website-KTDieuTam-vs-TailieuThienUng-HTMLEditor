(function () {
      var form = document.getElementById('employerJobForm');
      if (!form) return;

      var modeConfig = {
        'tao-moi': {
          kicker: 'Đăng tin tuyển dụng',
          heroTitle: 'Đăng tin tuyển dụng mới',
          heroText: 'Điền thông tin vị trí, xem trước nội dung và đăng khi đã sẵn sàng.',
          panelTitle: 'Tạo mới tin tuyển dụng',
          panelNote: 'Điền đầy đủ thông tin để tin tuyển dụng rõ ràng, dễ tìm và dễ ứng tuyển.',
          modeBadgeText: 'Tin mới',
          modeBadgeState: 'is-reviewing',
          modeBanner: 'Nên hoàn thiện tiêu đề, mô tả, yêu cầu và người phụ trách hồ sơ trước khi đăng.'
        },
        'sua': {
          kicker: 'Cập nhật tin tuyển dụng',
          heroTitle: 'Chỉnh sửa nội dung tin tuyển dụng',
          heroText: 'Rà lại nội dung để ứng viên luôn thấy thông tin mới và chính xác.',
          panelTitle: 'Cập nhật tin tuyển dụng',
          panelNote: 'Hãy cập nhật các thông tin quan trọng để tin luôn chính xác.',
          modeBadgeText: 'Cập nhật tin',
          modeBadgeState: 'is-warning',
          modeBanner: 'Nhớ kiểm tra hạn nộp hồ sơ và người phụ trách hồ sơ trước khi lưu.'
        },
        'nhan-ban': {
          kicker: 'Đăng lại tin tuyển dụng',
          heroTitle: 'Đăng lại từ tin trước đó',
          heroText: 'Chỉnh các thông tin cần thiết rồi đăng lại để tiếp tục nhận hồ sơ.',
          panelTitle: 'Đăng lại tin tuyển dụng',
          panelNote: 'Bạn có thể dùng nội dung cũ và chỉnh lại các thông tin quan trọng trước khi đăng.',
          modeBadgeText: 'Đăng lại',
          modeBadgeState: 'is-muted',
          modeBanner: 'Nên cập nhật tiêu đề, hạn nộp hồ sơ và người phụ trách hồ sơ trước khi đăng lại.'
        }
      };

      var statusMeta = {
        'nhap': { label: 'Nháp', state: 'is-reviewing' },
        'dang-tuyen': { label: 'Đang tuyển', state: 'is-active' },
        'tam-dung': { label: 'Tạm dừng', state: 'is-warning' },
        'da-dong': { label: 'Đã đóng', state: 'is-muted' }
      };

      var priorityMeta = {
        'normal': 'Tiêu chuẩn',
        'featured': 'Nổi bật',
        'urgent': 'Gấp'
      };

      function pad(value) {
        return String(value).padStart(2, '0');
      }

      function toDateInputValue(date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
      }

      function futureDate(days) {
        var date = new Date();
        date.setDate(date.getDate() + days);
        return toDateInputValue(date);
      }

      function clone(obj) {
        return JSON.parse(JSON.stringify(obj));
      }

      function clean(value) {
        return String(value || '').trim();
      }

      function slugify(value) {
        return clean(value)
          .toLowerCase()
          .normalize('NFD')
          .replace(/[\u0300-\u036f]/g, '')
          .replace(/đ/g, 'd')
          .replace(/[^a-z0-9]+/g, '-')
          .replace(/(^-|-$)+/g, '');
      }

      function normalizeStatus(statusValue) {
        var status = clean(statusValue).toLowerCase();
        if (!status) return 'nhap';
        if (status === 'dang_tuyen') return 'dang-tuyen';
        if (status === 'tam_dung') return 'tam-dung';
        if (status === 'da_dong') return 'da-dong';
        return statusMeta[status] ? status : 'nhap';
      }

      function labelOf(selectId) {
        var select = document.getElementById(selectId);
        if (!select || !select.options || select.selectedIndex < 0) return '';
        return clean(select.options[select.selectedIndex].text);
      }

      function isEmail(value) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(clean(value));
      }

      function hasPhone(value) {
        return clean(value).replace(/\D/g, '').length >= 8;
      }

      function setPillState(node, text, stateClass) {
        if (!node) return;
        node.textContent = text;
        node.classList.remove('is-active', 'is-reviewing', 'is-muted', 'is-warning', 'is-saved');
        if (stateClass) {
          node.classList.add(stateClass);
        }
      }

      function appendCopySuffix(title) {
        if (!title) return 'Bản sao tin tuyển dụng';
        if (/\(bản sao\)$/i.test(title)) return title;
        return title + ' (Bản sao)';
      }

      function formatDateForPreview(dateValue) {
        if (!dateValue) return 'Chưa có hạn nộp hồ sơ';
        var date = new Date(dateValue + 'T00:00:00');
        if (Number.isNaN(date.getTime())) return dateValue;
        return date.toLocaleDateString('vi-VN');
      }

      function buildJobId(data) {
        var titlePart = slugify(data.title || 'tin-tuyen-dung');
        var companyPart = slugify(data.companyName || 'doanh-nghiep');
        var composed = [titlePart, companyPart].filter(Boolean).join('-');
        return composed.slice(0, 120) || ('tin-tuyen-dung-' + Date.now());
      }

      var defaultDraft = {
        job_id: '',
        title: '',
        companyName: '',
        location: '',
        roleGroupKey: '',
        locationGroupKey: 'toan-quoc',
        quantity: '1',
        salaryLabel: '',
        deadline: futureDate(21),
        employmentType: 'full-time',
        workMode: 'onsite',
        experienceLevel: 'fresher',
        summary: '',
        description: '',
        requirements: '',
        benefits: '',
        contactName: '',
        contactPhone: '',
        contactEmail: '',
        applicationMethod: 'platform',
        applicationContact: 'ung-tuyen.html',
        status: 'nhap',
        priority: 'normal'
      };

      var presetJobs = {
        'ke-toan-tong-hop-cong-ty-tnhh-abc': {
          job_id: 'ke-toan-tong-hop-cong-ty-tnhh-abc',
          title: 'Tuyển dụng Kế toán tổng hợp',
          companyName: 'Công ty TNHH ABC',
          location: 'Quận 7, TP.HCM',
          roleGroupKey: 'ke-toan-tong-hop',
          locationGroupKey: 'tp-hcm',
          quantity: '1',
          salaryLabel: '15 - 18 triệu',
          deadline: futureDate(20),
          employmentType: 'full-time',
          workMode: 'onsite',
          experienceLevel: '2-nam',
          summary: 'Theo dõi chứng từ, tổng hợp số liệu và lập báo cáo kế toán định kỳ cho doanh nghiệp thương mại.',
          description: '• Kiểm tra, hạch toán chứng từ đầu vào - đầu ra theo đúng quy định.\n• Theo dõi công nợ, đối chiếu số liệu với các bộ phận liên quan.\n• Lập báo cáo thuế, báo cáo quản trị theo tháng/quý.\n• Phối hợp chuẩn bị hồ sơ quyết toán theo yêu cầu của quản lý.',
          requirements: '• Tối thiểu 2 năm kinh nghiệm ở vị trí kế toán tổng hợp.\n• Sử dụng tốt Excel và phần mềm kế toán.\n• Nắm chắc nghiệp vụ thuế GTGT, TNCN, TNDN.\n• Cẩn thận, chủ động, có tinh thần phối hợp đội nhóm.',
          benefits: '• Thu nhập cạnh tranh theo năng lực.\n• Đầy đủ BHXH, BHYT, BHTN theo quy định.\n• Thưởng lễ/tết và phụ cấp công việc.',
          contactName: 'Phòng Nhân sự',
          contactPhone: '0909 123 456',
          contactEmail: 'hr@congtyabc.vn',
          applicationMethod: 'platform',
          applicationContact: 'ung-tuyen.html',
          status: 'nhap',
          priority: 'featured'
        },
        'ke-toan-thue-cong-ty-tnhh-abc': {
          job_id: 'ke-toan-thue-cong-ty-tnhh-abc',
          title: 'Tuyển dụng Kế toán thuế',
          companyName: 'Công ty TNHH ABC',
          location: 'Quận 7, TP.HCM',
          roleGroupKey: 'ke-toan-thue',
          locationGroupKey: 'tp-hcm',
          quantity: '1',
          salaryLabel: '16 - 20 triệu',
          deadline: futureDate(15),
          employmentType: 'full-time',
          workMode: 'onsite',
          experienceLevel: '3-nam',
          summary: 'Phụ trách kê khai thuế, kiểm tra hồ sơ đầu vào/đầu ra và hỗ trợ chuẩn bị quyết toán thuế.',
          description: '• Kiểm tra hóa đơn, chứng từ phục vụ kê khai thuế.\n• Lập và nộp các tờ khai thuế định kỳ đúng hạn.\n• Rà soát rủi ro thuế và đề xuất hướng xử lý.\n• Phối hợp làm việc với cơ quan thuế khi phát sinh.',
          requirements: '• Tối thiểu 3 năm kinh nghiệm mảng kế toán thuế.\n• Thành thạo phần mềm hỗ trợ kê khai và Excel.\n• Cập nhật tốt quy định thuế hiện hành.\n• Kỹ năng phân tích, cẩn thận trong đối chiếu số liệu.',
          benefits: '• Lương cạnh tranh + thưởng hiệu suất.\n• Môi trường làm việc ổn định, quy trình rõ ràng.\n• Được hỗ trợ chi phí học tập nâng cao nghiệp vụ.',
          contactName: 'Chị Linh - HR',
          contactPhone: '0912 222 333',
          contactEmail: 'tuyendung@congtyabc.vn',
          applicationMethod: 'platform',
          applicationContact: 'ung-tuyen.html',
          status: 'nhap',
          priority: 'urgent'
        },
        'ke-toan-noi-bo-cong-ty-tnhh-abc': {
          job_id: 'ke-toan-noi-bo-cong-ty-tnhh-abc',
          title: 'Tuyển dụng Kế toán nội bộ',
          companyName: 'Công ty TNHH ABC',
          location: 'Thủ Đức, TP.HCM',
          roleGroupKey: 'ke-toan-noi-bo',
          locationGroupKey: 'tp-hcm',
          quantity: '2',
          salaryLabel: '10 - 13 triệu',
          deadline: futureDate(10),
          employmentType: 'full-time',
          workMode: 'onsite',
          experienceLevel: '1-nam',
          summary: 'Theo dõi thu - chi nội bộ, kiểm tra chứng từ và tổng hợp số liệu phục vụ quản trị doanh nghiệp.',
          description: '• Theo dõi và hạch toán các nghiệp vụ thu chi hàng ngày.\n• Kiểm tra chứng từ, đối chiếu công nợ nội bộ.\n• Lập báo cáo doanh thu - chi phí theo tuần/tháng.\n• Hỗ trợ công việc phát sinh theo phân công của kế toán trưởng.',
          requirements: '• Có kinh nghiệm tối thiểu 1 năm ở vị trí tương đương.\n• Sử dụng tốt Excel, ưu tiên biết phần mềm MISA.\n• Tinh thần trách nhiệm và tỉ mỉ với chứng từ số liệu.',
          benefits: '• Mức lương ổn định theo năng lực.\n• Đào tạo thêm nghiệp vụ trong thời gian làm việc.\n• Chế độ nghỉ lễ, phép năm theo quy định.',
          contactName: 'Anh Tuấn - HCNS',
          contactPhone: '0933 555 888',
          contactEmail: 'hr@congtyabc.vn',
          applicationMethod: 'platform',
          applicationContact: 'ung-tuyen.html',
          status: 'da-dong',
          priority: 'normal'
        }
      };

      var fieldIdMap = {
        title: 'jobPostTitle',
        companyName: 'jobPostCompanyName',
        location: 'jobPostLocation',
        roleGroupKey: 'jobPostRoleGroupKey',
        locationGroupKey: 'jobPostLocationGroupKey',
        quantity: 'jobPostQuantity',
        salaryLabel: 'jobPostSalaryLabel',
        deadline: 'jobPostDeadline',
        employmentType: 'jobPostEmploymentType',
        workMode: 'jobPostWorkMode',
        experienceLevel: 'jobPostExperienceLevel',
        summary: 'jobPostSummary',
        description: 'jobPostDescription',
        requirements: 'jobPostRequirements',
        benefits: 'jobPostBenefits',
        contactName: 'jobPostContactName',
        contactPhone: 'jobPostContactPhone',
        contactEmail: 'jobPostContactEmail',
        applicationMethod: 'jobPostApplicationMethod',
        applicationContact: 'jobPostApplicationContact',
        status: 'jobPostStatus',
        priority: 'jobPostPriority'
      };

      function mergeData(target, source) {
        var merged = clone(target);
        Object.keys(source || {}).forEach(function (key) {
          if (source[key] !== undefined && source[key] !== null) {
            merged[key] = String(source[key]);
          }
        });
        return merged;
      }

      var query = new URLSearchParams(window.location.search);
      var modeParam = clean(query.get('mode')).toLowerCase();
      var activeMode = modeConfig[modeParam] ? modeParam : 'tao-moi';
      var requestedJobId = clean(query.get('job_id'));
      var presetFound = Boolean(requestedJobId && presetJobs[requestedJobId]);

      var initialData = clone(defaultDraft);
      if ((activeMode === 'sua' || activeMode === 'nhan-ban') && presetFound) {
        initialData = mergeData(initialData, presetJobs[requestedJobId]);
      }

      if (activeMode === 'sua') {
        initialData.job_id = requestedJobId || initialData.job_id;
      }

      if (activeMode === 'nhan-ban') {
        initialData.title = appendCopySuffix(initialData.title);
        initialData.job_id = '';
        initialData.status = 'nhap';
        initialData.priority = 'normal';
      }

      initialData.status = normalizeStatus(initialData.status);
      if (!initialData.deadline) {
        initialData.deadline = futureDate(21);
      }

      function fillForm(data) {
        Object.keys(fieldIdMap).forEach(function (key) {
          var node = document.getElementById(fieldIdMap[key]);
          if (!node) return;
          node.value = data[key] !== undefined ? data[key] : '';
        });
        document.getElementById('jobPostIdInput').value = clean(data.job_id);
        document.getElementById('jobPostFormModeInput').value = activeMode;
      }

      function applyModeTexts() {
        var config = modeConfig[activeMode];
        document.getElementById('jobFormHeroKicker').textContent = config.kicker;
        document.getElementById('jobFormHeroTitle').textContent = config.heroTitle;
        document.getElementById('jobFormHeroText').textContent = config.heroText;
        document.getElementById('jobFormPanelTitle').textContent = config.panelTitle;

        var panelNote = config.panelNote;
        if ((activeMode === 'sua' || activeMode === 'nhan-ban') && requestedJobId && !presetFound) {
          panelNote += ' Một số thông tin chưa có sẵn, bạn vui lòng kiểm tra kỹ trước khi lưu.';
        }
        document.getElementById('jobFormPanelNote').textContent = panelNote;
        document.getElementById('jobFormModeBanner').textContent = config.modeBanner;
        setPillState(document.getElementById('jobFormModeBadge'), config.modeBadgeText, config.modeBadgeState);
      }

      function collectData() {
        var payload = {};
        Object.keys(fieldIdMap).forEach(function (key) {
          var node = document.getElementById(fieldIdMap[key]);
          payload[key] = node ? clean(node.value) : '';
        });
        payload.status = normalizeStatus(payload.status);
        payload.quantity = payload.quantity || '1';
        payload.job_id = clean(document.getElementById('jobPostIdInput').value);
        payload.formMode = clean(document.getElementById('jobPostFormModeInput').value);
        return payload;
      }

      function updateCharCounts() {
        var counters = document.querySelectorAll('.jobs-char-count[data-count-for]');
        Array.prototype.forEach.call(counters, function (counter) {
          var targetId = counter.getAttribute('data-count-for');
          var max = parseInt(counter.getAttribute('data-max') || '0', 10);
          var node = document.getElementById(targetId);
          var length = node ? clean(node.value).length : 0;
          counter.textContent = max ? (length + '/' + max) : (length + ' ký tự');
          counter.classList.toggle('is-warning', Boolean(max && length >= Math.floor(max * 0.9)));
        });
      }

      function renderTags(tags) {
        var wrap = document.getElementById('jobPreviewTags');
        if (!wrap) return;
        wrap.innerHTML = '';
        tags.filter(Boolean).forEach(function (label) {
          var tag = document.createElement('span');
          tag.className = 'jobs-job-preview-tag';
          tag.textContent = label;
          wrap.appendChild(tag);
        });
      }

      function updatePreview() {
        var data = collectData();
        var status = statusMeta[data.status] || statusMeta['nhap'];

        document.getElementById('jobPreviewTitle').textContent = data.title || 'Tin tuyển dụng của bạn';
        document.getElementById('jobPreviewCompany').textContent = data.companyName || 'Tên doanh nghiệp';
        document.getElementById('jobPreviewLocation').textContent = data.location || 'Khu vực làm việc';
        document.getElementById('jobPreviewEmployment').textContent = (labelOf('jobPostEmploymentType') || 'Chưa chọn hình thức') + ' · ' + (labelOf('jobPostWorkMode') || 'Chưa chọn cách làm việc');
        document.getElementById('jobPreviewDeadline').textContent = formatDateForPreview(data.deadline);
        document.getElementById('jobPreviewQuantity').textContent = 'Số lượng: ' + (data.quantity || '1') + ' người';
        document.getElementById('jobPreviewApply').textContent = 'Ứng viên nộp hồ sơ trên Diệu Tâm';
        document.getElementById('jobPreviewSummary').textContent = data.summary || 'Tóm tắt ngắn sẽ hiển thị ở đây để bạn kiểm tra trước khi đăng.';

        var salarySpan = document.querySelector('#jobPreviewSalary span');
        if (salarySpan) {
          salarySpan.textContent = data.salaryLabel || 'Lương thỏa thuận';
        }

        setPillState(document.getElementById('jobPreviewStatus'), status.label, status.state);

        renderTags([
          labelOf('jobPostRoleGroupKey'),
          labelOf('jobPostExperienceLevel'),
          priorityMeta[data.priority] ? ('Ưu tiên: ' + priorityMeta[data.priority]) : '',
          data.status === 'dang-tuyen' ? 'Đang nhận hồ sơ' : ''
        ]);
      }

      function setCheckState(id, passed) {
        var node = document.getElementById(id);
        if (!node) return;
        node.classList.toggle('is-done', Boolean(passed));
      }

      function updateChecklist() {
        var data = collectData();
        var checks = {
          title: data.title.length >= 12 && data.roleGroupKey.length > 0,
          summary: data.summary.length >= 40,
          details: data.description.length >= 120 && data.requirements.length >= 60,
          deadline: data.salaryLabel.length > 0 && data.deadline.length > 0,
          contact: isEmail(data.contactEmail) || hasPhone(data.contactPhone)
        };

        setCheckState('jobCheckTitle', checks.title);
        setCheckState('jobCheckSummary', checks.summary);
        setCheckState('jobCheckDetails', checks.details);
        setCheckState('jobCheckDeadline', checks.deadline);
        setCheckState('jobCheckContact', checks.contact);

        var passedCount = Object.keys(checks).filter(function (key) { return checks[key]; }).length;
        var totalCount = Object.keys(checks).length;
        document.getElementById('jobChecklistSummary').textContent = passedCount + '/' + totalCount + ' mục đã đạt.';
      }

      function showFeedback(message, type) {
        var feedback = document.getElementById('jobFormFeedback');
        if (!feedback) return;
        feedback.hidden = false;
        feedback.textContent = message;
        feedback.classList.remove('is-success', 'is-warning');
        if (type) {
          feedback.classList.add(type === 'warning' ? 'is-warning' : 'is-success');
        }
      }

      function refreshUi() {
        updateCharCounts();
        updatePreview();
        updateChecklist();
      }

      applyModeTexts();
      fillForm(initialData);
      refreshUi();

      var trackedInputIds = Object.keys(fieldIdMap).map(function (key) { return fieldIdMap[key]; });
      trackedInputIds.forEach(function (inputId) {
        var input = document.getElementById(inputId);
        if (!input) return;
        input.addEventListener('input', refreshUi);
        input.addEventListener('change', refreshUi);
      });

      var previewBtn = document.getElementById('jobPreviewBtn');
      if (previewBtn) {
        previewBtn.addEventListener('click', function () {
          refreshUi();
          var previewCard = document.getElementById('jobPreviewCard');
          if (previewCard) {
            previewCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
          showFeedback('Đã làm mới bản xem trước.', 'success');
        });
      }

      var lastSubmitAction = 'luu-nhap';
      var submitButtons = form.querySelectorAll('button[type="submit"][name="submitAction"]');
      Array.prototype.forEach.call(submitButtons, function (button) {
        button.addEventListener('click', function () {
          lastSubmitAction = clean(button.value) || 'luu-nhap';
        });
      });

      form.addEventListener('submit', function (event) {
        event.preventDefault();

        var submitter = event.submitter;
        var submitAction = clean(submitter && submitter.value ? submitter.value : lastSubmitAction) || 'luu-nhap';
        var payload = collectData();

        if (!payload.job_id) {
          payload.job_id = buildJobId(payload);
          document.getElementById('jobPostIdInput').value = payload.job_id;
        }

        if (submitAction === 'dang-tin' && (payload.status === 'nhap' || payload.status === 'tam-dung')) {
          payload.status = 'dang-tuyen';
          document.getElementById('jobPostStatus').value = payload.status;
        }

        try {
          localStorage.setItem('jobs-employer-last-run', JSON.stringify({
            at: new Date().toISOString(),
            action: submitAction,
            mode: activeMode,
            payload: payload
          }));
        } catch (error) {
          // Bỏ qua lỗi localStorage khi trình duyệt chặn quyền ghi.
        }

        refreshUi();
        showFeedback(
          submitAction === 'dang-tin'
            ? 'Đăng tin thành công. Bạn sẽ được chuyển tới trang quản lý tin sau giây lát.'
            : 'Đã lưu nháp. Bạn sẽ được chuyển tới trang quản lý tin sau giây lát.',
          'success'
        );

        var redirect = new URL('quan-ly-tin-tuyen-dung.html', window.location.href);
        redirect.searchParams.set('tab', submitAction === 'luu-nhap' ? 'nhap' : 'tat-ca');
        redirect.searchParams.set('action', submitAction);
        redirect.searchParams.set('job_id', payload.job_id);
        redirect.searchParams.set('status', payload.status);

        window.setTimeout(function () {
          window.location.href = redirect.toString();
        }, 360);
      });
    })();
