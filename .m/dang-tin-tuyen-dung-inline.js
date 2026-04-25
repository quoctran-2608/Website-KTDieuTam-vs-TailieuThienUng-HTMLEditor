(function () {
      var form = document.getElementById('recruitmentLeadForm');
      var preview = document.getElementById('requestPreview');
      var result = document.getElementById('requestResult');
      var requestIdText = document.getElementById('requestIdText');
      var downloadBtn = document.getElementById('downloadBriefBtn');
      var downloadBtnInline = document.getElementById('downloadBriefBtnInline');
      var copyBtn = document.getElementById('copyBriefBtn');
      var resultStatus = document.getElementById('requestResultStatus');
      var zaloUrl = 'https://zalo.me/0777315188';
      var lastPayload = null;

      function value(id) {
        var el = document.getElementById(id);
        return el ? String(el.value || '').trim() : '';
      }

      function buildPayload() {
        var timestamp = new Date();
        var createdAt = timestamp.toISOString().slice(0, 19);
        var requestId = 'brief-' + createdAt.replace(/[:T]/g, '-');
        return {
          requestId: requestId,
          companyName: value('companyName'),
          contactName: value('contactName'),
          contactPhone: value('contactPhone'),
          contactEmail: value('contactEmail'),
          jobTitle: value('jobTitle'),
          jobLocation: value('jobLocation'),
          jobQuantity: value('jobQuantity') || '1',
          jobDeadline: value('jobDeadline'),
          employmentType: value('employmentType'),
          workMode: value('workMode'),
          applicationMethod: value('applicationMethod'),
          applicationContact: value('applicationContact'),
          salaryLabel: value('salaryLabel'),
          experienceLevel: value('experienceLevel'),
          jobNotes: value('jobNotes'),
          sourcePage: 'dang-tin-tuyen-dung.html',
          sourceChannel: 'website-brief',
          createdAt: createdAt
        };
      }

      function formatRequestCode(requestId) {
        return String(requestId || '').replace(/^brief-/, 'YC-');
      }

      function buildDownloadName(requestId) {
        return 'yeu-cau-tuyen-dung-' + formatRequestCode(requestId).toLowerCase() + '.txt';
      }

      function buildBrief(payload) {
        return [
          'Thông tin tuyển dụng gửi Diệu Tâm',
          'Mã tham chiếu: ' + formatRequestCode(payload.requestId),
          'Công ty: ' + payload.companyName,
          'Người phụ trách hồ sơ: ' + payload.contactName,
          'SĐT/Zalo: ' + payload.contactPhone,
          'Email nhận thông báo đơn mới: ' + (payload.contactEmail || 'Chưa cung cấp'),
          'Vị trí cần tuyển: ' + payload.jobTitle,
          'Khu vực làm việc: ' + payload.jobLocation,
          'Số lượng: ' + (payload.jobQuantity || '1'),
          'Hạn nộp hồ sơ: ' + (payload.jobDeadline || 'Chưa chốt'),
          'Hình thức: ' + payload.employmentType,
          'Cách làm việc: ' + payload.workMode,
          'Cách ứng tuyển: Ứng viên nộp hồ sơ trên Diệu Tâm',
          'Lương tham khảo: ' + (payload.salaryLabel || 'Chưa công khai'),
          'Yêu cầu kinh nghiệm: ' + (payload.experienceLevel || 'Chưa ghi rõ'),
          'Ghi chú thêm: ' + (payload.jobNotes || 'Không có ghi chú thêm')
        ].join('\\n');
      }

      function ensureValidForm() {
        if (!form) return false;
        if (typeof form.reportValidity === 'function') {
          return form.reportValidity();
        }
        return true;
      }

      function renderResult(payload, note) {
        var brief = buildBrief(payload);
        lastPayload = payload;
        preview.textContent = brief;
        if (requestIdText) {
          requestIdText.textContent = formatRequestCode(payload.requestId);
        }
        if (resultStatus) {
          resultStatus.textContent = note || 'Nội dung đã sẵn sàng. Bạn có thể sao chép hoặc mở Zalo để gửi.';
        }
        result.hidden = false;
        result.scrollIntoView({ behavior: 'smooth', block: 'start' });
        return brief;
      }

      function downloadBrief(payload) {
        var blob = new Blob([buildBrief(payload)], { type: 'text/plain;charset=utf-8' });
        var href = URL.createObjectURL(blob);
        var anchor = document.createElement('a');
        anchor.href = href;
        anchor.download = buildDownloadName(payload.requestId);
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        URL.revokeObjectURL(href);
      }

      function submitBrief(event) {
        event.preventDefault();
        if (!ensureValidForm()) return;
        var payload = buildPayload();
        renderResult(payload, 'Nội dung đã tạo xong. Bạn có thể sao chép, tải file hoặc mở Zalo để gửi.');
      }

      function handleDownload() {
        if (!ensureValidForm()) return;
        var payload = lastPayload || buildPayload();
        downloadBrief(payload);
        renderResult(payload, 'Đã tạo và tải file nội dung. Bạn có thể tiếp tục sao chép hoặc mở Zalo để gửi.');
      }

      function handleCopy() {
        if (!ensureValidForm()) return;
        var payload = lastPayload || buildPayload();
        var brief = renderResult(payload, 'Đang chuẩn bị nội dung để sao chép...');
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(brief).then(function () {
            if (resultStatus) {
              resultStatus.textContent = 'Đã sao chép nội dung. Bạn có thể mở Zalo và dán ngay.';
            }
          }).catch(function () {
            if (resultStatus) {
              resultStatus.textContent = 'Không thể sao chép tự động. Bạn có thể bôi đen phần nội dung bên dưới để sao chép thủ công.';
            }
          });
          return;
        }
        if (resultStatus) {
          resultStatus.textContent = 'Trình duyệt này không hỗ trợ sao chép tự động. Bạn có thể bôi đen phần nội dung bên dưới để sao chép thủ công.';
        }
      }

      if (form) {
        form.addEventListener('submit', submitBrief);
      }
      if (downloadBtn) {
        downloadBtn.addEventListener('click', handleDownload);
      }
      if (downloadBtnInline) {
        downloadBtnInline.addEventListener('click', handleDownload);
      }
      if (copyBtn) {
        copyBtn.addEventListener('click', handleCopy);
      }
    })();
