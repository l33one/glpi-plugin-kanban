/**
 * GLPI Kanban Plugin - JS Controller
 */
(function () {
   'use strict';

   // Cache DOM references
   const filterForm = document.getElementById('kanban-filter-form');
   const boardContainer = document.getElementById('kanban-board');
   const loadingOverlay = document.getElementById('kanban-loading-overlay');
   const csrfInput = document.querySelector('input[name="_glpi_csrf_token"]');
   let csrfToken = csrfInput ? csrfInput.value : '';

// State
let timerInterval = null;
let hasVisibleTimers = false;
let ticketsByStatus = {};
let columnSorts = {};
const lang = window.KANBAN_TRANSLATIONS || { open: 'Open', unassigned: 'Unassigned', noCategory: 'No Category', loading: 'Loading...', sortHint: 'Sort via column dropdowns', sortBy: 'Sort by', noTickets: 'No tickets' };

// Build full API URL using GLPI root
const glpiRoot = window.KANBAN_GLPI_ROOT || '';
const apiUrl = glpiRoot + '/plugins/kanban/front/kanban.php';

const priorityColors = {
   1: '#ced4da',
   2: '#adc5e3',
   3: '#0d6efd',
   4: '#ffc107',
   5: '#fd7e14',
   6: '#dc3545'
};

const priorityLabels = (window.KANBAN_TRANSLATIONS && window.KANBAN_TRANSLATIONS.priorityLabels) || { 1: 'Very Low', 2: 'Low', 3: 'Normal', 4: 'High', 5: 'Very High', 6: 'Major' };


const statusColors = {
   1: 'new',
   2: 'assigned',
   3: 'planned',
   4: 'pending',
   5: 'solved',
   6: 'closed'
};

const statusIcons = {
   1: { icon: 'circle', filled: true, color: 'success' },
   2: { icon: 'circle', filled: false, color: 'success' },
   3: { icon: 'calendar', filled: false, color: 'dark' },
   4: { icon: 'circle', filled: true, color: 'warning' },
   5: { icon: 'circle', filled: false, color: 'dark' },
   6: { icon: 'circle', filled: true, color: 'dark' }
};

   /**
    * Show/hide loading overlay
    */
   function showLoading() {
      if (loadingOverlay) {
         loadingOverlay.style.display = 'flex';
      }
   }

   function hideLoading() {
      if (loadingOverlay) {
         loadingOverlay.style.display = 'none';
      }
   }

   /**
    * Escape HTML entities
    */
   function escapeHtml(str) {
      if (!str) return '';
      const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' };
      return str.replace(/[&<>'"]/g, tag => map[tag] || tag);
   }

   /**
    * Render the Kanban Board structure initially
    */
function getStatusColorStyle(si) {
   if (!si) return '';
   const colorMap = { success: '#28a745', warning: '#ffc107', danger: '#dc3545', info: '#17a2b8', dark: '#343a40', primary: '#0d6efd', secondary: '#6c757d' };
   const color = colorMap[si.color] || '#6c757d';
   return si.filled ? 'font-weight: 900; color: ' + color + ';' : 'font-weight: 400; border: 2px solid ' + color + '; border-radius: 50%;';
}
function renderBoardColumns() {
       if (!boardContainer) return;

       const statuses = window.KANBAN_STATUSES || {
          1: { name: 'New', color: 'primary' },
          2: { name: 'Assigned', color: 'info' },
          3: { name: 'Planned', color: 'warning' },
          4: { name: 'Pending', color: 'secondary' },
          5: { name: 'Solved', color: 'success' },
          6: { name: 'Closed', color: 'dark' }
       };

        let boardHtml = '';
       for (const [id, status] of Object.entries(statuses)) { status.id = id;
          const statusClass = statusColors[status.id] || 'secondary';
          boardHtml += `
             <div class="kanban-column status-${statusClass}" data-status-id="${id}" data-status-name="${statusClass}">
                <div class="kanban-column-header d-flex align-items-center justify-content-between">
                   <div class="d-flex align-items-center gap-2">
                      <span class="status-indicator d-inline-flex align-items-center justify-content-center" style="width: 14px; height: 14px;"><i class="ti ${statusIcons[status.id] ? 'ti-' + statusIcons[status.id].icon : ''}" style="${getStatusColorStyle(statusIcons[status.id])}"></i></span>
                      <h5 class="m-0">${escapeHtml(status.name)}</h5>
                   </div>
                   <div class="d-flex align-items-center gap-1">
                      <span class="badge rounded-pill fs-7 card-count card-count-badge" id="count-${id}" aria-live="polite" aria-label="${escapeHtml(status.name)} tickets count">--</span>
                      <div class="dropdown">
                         <button class="btn btn-sm kanban-sort-btn" type="button"
                                id="sort-btn-${id}" data-bs-toggle="dropdown" aria-expanded="false"
                                title="${lang.sortHint}" aria-label="${lang.sortHint}">
                            <i class="ti ti-arrows-sort"></i>
                         </button>
                         <ul class="dropdown-menu dropdown-menu-end kanban-sort-menu" aria-labelledby="sort-btn-${id}">
                         </ul>
                      </div>
                   </div>
                </div>
                <div class="kanban-cards-dropzone d-flex flex-column gap-3" id="status-column-${id}" data-status="${id}">
                   <div class="empty-column-placeholder" id="empty-${id}">
                      <i class="ti ti-inbox"></i>
                      <span>${escapeHtml(status.name)} - ${lang.noTickets}</span>
                   </div>
                </div>
             </div>
          `;
       }
       boardContainer.innerHTML = boardHtml;

       // Populate sort dropdowns with translated labels
       const sortOptions = window.KANBAN_SORT_OPTIONS || {};
       document.querySelectorAll('.kanban-column .dropdown').forEach(dropdown => {
          const statusId = dropdown.closest('.kanban-column').getAttribute('data-status-id');
          const menu = dropdown.querySelector('.dropdown-menu');
          if (!menu) return;
           menu.innerHTML = '';

           const header = document.createElement('li');
           header.className = 'dropdown-header kanban-sort-header';
           header.textContent = lang.sortBy || 'Sort by';
           menu.appendChild(header);

           for (const [value, label] of Object.entries(sortOptions)) {
              const li = document.createElement('li');
              const a = document.createElement('a');
              a.className = 'dropdown-item kanban-sort-option';
              a.href = '#';
              a.setAttribute('data-status', statusId);
              a.setAttribute('data-value', value);

              const check = document.createElement('span');
              check.className = 'kanban-sort-check';
              check.innerHTML = '<i class="ti ti-check"></i>';

              const lbl = document.createElement('span');
              lbl.textContent = label;

              a.appendChild(check);
              a.appendChild(lbl);
              li.appendChild(a);
              menu.appendChild(li);
           }
        });

       // Attach sort listeners using event delegation
       boardContainer.addEventListener('click', function(e) {
          const item = e.target.closest('.kanban-sort-option');
          if (!item) return;
          e.preventDefault();

          const statusId = item.getAttribute('data-status');
          const value = item.getAttribute('data-value');

          // Remember the chosen sort for this column (applies on future refreshes)
          columnSorts[statusId] = value;

          // Update the button to show current sort + active styling
          const btn = document.getElementById('sort-btn-' + statusId);
          if (btn) {
             btn.classList.add('is-sorted');
             btn.setAttribute('aria-expanded', 'false');
             btn.innerHTML = '<i class="ti ti-arrows-up-down"></i> <span class="kanban-sort-label">' + item.textContent.trim() + '</span>';
          }

          // Mark the active option with a check
          const menu = item.closest('.dropdown-menu');
          if (menu) {
             menu.querySelectorAll('.kanban-sort-option').forEach(opt => opt.classList.remove('active'));
             item.classList.add('active');
          }

          // Re-sort the column
          if (ticketsByStatus[statusId]) {
             const zone = document.getElementById('status-column-' + statusId);
             if (zone) {
                const sorted = sortTicketsByValue(ticketsByStatus[statusId], value);
                zone.innerHTML = '';
                sorted.forEach(t => zone.appendChild(createCardElement(t)));
                             }
          }
        });
    }

   /**
    * Show empty placeholder in a column with no tickets
    */
   function showEmptyPlaceholder(zone, statusId) {
      if (!zone) return;
      const statuses = window.KANBAN_STATUSES || {};
      const status = statuses[statusId] || { name: '' };
      zone.innerHTML = `
         <div class="empty-column-placeholder">
            <i class="ti ti-inbox"></i>
            <span>${escapeHtml(status.name || '')} - ${lang.noTickets}</span>
         </div>
      `;
   }

   /**
    * Fetch and render tickets via AJAX
    */
function loadTickets() {
       if (!filterForm) return;

       // Clear existing timer to prevent leaks
       if (timerInterval) {
          clearInterval(timerInterval);
          timerInterval = null;
       }
       hasVisibleTimers = false;

       showLoading();

       const formData = new FormData(filterForm);
       const params = new URLSearchParams();
       params.append('action', 'get_tickets');
       params.append('_t', Date.now());

       for (const [key, value] of formData.entries()) {
          if (value) {
             params.append(key, value);
          }
       }

       fetch(apiUrl + '?' + params.toString())
          .then(response => {
             if (!response.ok) throw new Error('HTTP ' + response.status);
             return response.json();
          })
          .then(data => {
             ticketsByStatus = data;

             // Clear all dropzones
             document.querySelectorAll('.kanban-cards-dropzone').forEach(zone => {
                zone.innerHTML = '';
             });

             // Populate columns
             let totalTickets = 0;
             for (const [statusId, tickets] of Object.entries(data)) {
                const zone = document.getElementById('status-column-' + statusId);
                const countBadge = document.getElementById('count-' + statusId);

                if (countBadge) {
                   countBadge.textContent = tickets.length;
                }

                if (zone && tickets.length > 0) {
                   const sorted = sortTicketsByValue(tickets, columnSorts[statusId] || 'date_DESC');
                   sorted.forEach(ticket => {
                      zone.appendChild(createCardElement(ticket));
                   });
                   totalTickets += tickets.length;
                } else if (zone) {
                   showEmptyPlaceholder(zone, statusId);
                }
             }

              // Update empty columns to show "0" instead of "--"
              document.querySelectorAll('.card-count').forEach(badge => {
                 if (badge.textContent === '--') {
                    badge.textContent = '0';
                 }
              });

              // Update total tickets counter in header
              const totalBadge = document.getElementById('kanban-total');
              if (totalBadge) {
                 totalBadge.textContent = totalTickets;
              }


                          updateCountdowns();
             startTimerIfNeeded();
             hideLoading();
          })
          .catch(err => {
             console.error('Error fetching tickets:', err);
             showToast('Failed to load tickets. Please try again.', 'danger');
             hideLoading();
          });
    }

/**
     * Sort tickets based on a sort value string (e.g., "date_DESC", "priority_ASC")
     */
    function sortTicketsByValue(tickets, sortValue) {
       const [sortBy, sortOrder] = sortValue.split('_');
       const sorted = [...tickets];

       sorted.sort((a, b) => {
          let cmp = 0;
          switch (sortBy) {
             case 'priority':
                cmp = (b.priority || 0) - (a.priority || 0);
                break;
             case 'status_duration':
                cmp = new Date(b.date_mod || 0) - new Date(a.date_mod || 0);
                break;
             case 'date':
             default:
                cmp = new Date(b.date || 0) - new Date(a.date || 0);
                break;
          }
          return sortOrder === 'ASC' ? cmp : -cmp;
       });

       return sorted;
    }

   /**
    * Create HTML card element for a Ticket
    */
function createCardElement(ticket) {
       const card = document.createElement('div');
       card.className = 'kanban-card card';
              card.setAttribute('data-ticket-id', ticket.id);
                     card.setAttribute('aria-label', 'Ticket #' + ticket.id + ' - ' + (ticket.title || ''));

       // Apply border styling based on priority
       card.style.borderLeftColor = priorityColors[ticket.priority] || '#0d6efd';

       // SLA Progress Bar HTML
       let slaBarHtml = '';
       if (ticket.sla_progress && ticket.sla_progress.status !== 'no_sla' && ticket.time_to_resolve) {
          slaBarHtml = `
             <div class="sla-progress mb-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                   <span class="d-flex align-items-center gap-1">
                      <i class="ti ti-clock"></i>
                      <span class="sla-countdown-timer fw-bold" data-deadline="${ticket.time_to_resolve}">--:--:--</span>
                   </span>
                   <small class="text-muted">${ticket.sla_progress.percent}%</small>
                </div>
                <div class="progress" style="height: 6px;">
                   <div class="progress-bar bg-${ticket.sla_progress.color}" role="progressbar"
                        style="width: ${ticket.sla_progress.percent}%"
                        aria-valuenow="${ticket.sla_progress.percent}" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
             </div>
          `;
          hasVisibleTimers = true;
       }

       // Technicians text
       const techNames = ticket.assigned_techs && ticket.assigned_techs.length > 0
          ? ticket.assigned_techs.join(', ')
          : lang.unassigned;

       // Priority badge
       const priorityBadge = `<span class="badge fs-8 text-nowrap px-2" style="background-color:${priorityColors[ticket.priority] || '#0d6efd'}; color:${(ticket.priority || 0) >= 4 ? '#000' : '#fff'}; min-width: 60px; justify-content: center;">${priorityLabels[ticket.priority] || 'Normal'}</span>`;

       // Construct the ticket URL
       const ticketUrl = glpiRoot + '/front/ticket.form.php?id=' + ticket.id;

       card.innerHTML = `
          <div class="card-body">
             <div class="kanban-card-top">
                <span class="ticket-id">#${ticket.id}</span>
                <div class="d-flex align-items-center gap-1 flex-shrink-0">
                   ${priorityBadge}
                   <a href="${ticketUrl}" target="_blank" class="card-open-link"
                      onclick="event.stopPropagation()" title="${lang.open} #${ticket.id}"
                      aria-label="${lang.open} #${ticket.id}">
                      <i class="ti ti-external-link"></i>
                   </a>
                </div>
             </div>
             <h6 class="card-title mb-0">
                <a href="${ticketUrl}" target="_blank" class="text-decoration-none"
                   onclick="event.stopPropagation()">
                   ${escapeHtml(ticket.title)}
                </a>
             </h6>
             ${slaBarHtml}
             <div class="card-meta">
                <div class="card-meta-item text-truncate" title="${escapeHtml(techNames)}">
                   <i class="ti ti-user"></i><span>${escapeHtml(techNames)}</span>
                </div>
                <div class="card-meta-item text-truncate" title="${escapeHtml(ticket.category || lang.noCategory)}">
                   <i class="ti ti-tag"></i><span>${escapeHtml(ticket.category || lang.noCategory)}</span>
                </div>
                <div class="card-meta-item ms-auto" title="${ticket.date_creation}">
                   <i class="ti ti-calendar"></i><span>${ticket.date_creation}</span>
                </div>
             </div>
          </div>
       `;

       // Click on card (not on links/buttons) opens detail modal
       card.addEventListener('click', function (e) {
          if (e.target.closest('a') || e.target.closest('button')) return;
          showTicketDetail(ticket);
       });

       // Keyboard accessibility
       card.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') {
             e.preventDefault();
             showTicketDetail(ticket);
          }
       });

       return card;
    }


   /**
    * Show a temporary toast notification at the top-right corner.
    */
   function showToast(message, type = 'info') {
      let container = document.getElementById('kanban-toast-container');
      if (!container) {
         container = document.createElement('div');
         container.id = 'kanban-toast-container';
         container.style.cssText = 'position:fixed;top:1rem;right:1rem;z-index:9999;display:flex;flex-direction:column;gap:0.5rem;';
         document.body.appendChild(container);
      }

      const toast = document.createElement('div');
      toast.className = 'alert alert-' + type + ' alert-dismissible shadow d-flex align-items-center gap-2 py-2 px-3';
      toast.style.cssText = 'min-width:260px;max-width:350px;animation:fadeIn 0.2s ease;';
      const iconMap = { success: 'circle-check', danger: 'alert-circle', warning: 'alert-triangle', info: 'info-circle' };
      toast.innerHTML = `
         <i class="ti ti-${iconMap[type] || 'info-circle'}"></i>
         <span>${message}</span>
         <button type="button" class="btn-close ms-auto" style="font-size:0.7rem;" onclick="this.closest('.alert').remove()"></button>
      `;
      container.appendChild(toast);

      // Auto-dismiss after 3.5 seconds
      setTimeout(() => {
         if (toast.parentNode) toast.remove();
      }, 3500);
   }

   // =============================================
   // Event Listeners with Debounce
   // =============================================

   let filterDebounceTimer = null;

   function debouncedLoadTickets() {
      if (filterDebounceTimer) clearTimeout(filterDebounceTimer);
      filterDebounceTimer = setTimeout(loadTickets, 300);
   }

   if (filterForm) {
      filterForm.querySelectorAll('select').forEach(select => {
         select.addEventListener('change', debouncedLoadTickets);
      });
   }

   // Refresh button listener
   const refreshBtn = document.getElementById('kanban-refresh-btn');
   if (refreshBtn) {
      refreshBtn.addEventListener('click', function () {
         const icon = this.querySelector('i');
         if (icon) {
            icon.style.transition = 'transform 0.5s ease';
            icon.style.transform = 'rotate(360deg)';
            setTimeout(() => {
               icon.style.transition = 'none';
               icon.style.transform = 'rotate(0deg)';
            }, 500);
         }
         loadTickets();
      });
   }

   // =============================================
   // SLA Countdown Logic
   // =============================================

function updateCountdowns() {
       const timers = document.querySelectorAll('.sla-countdown-timer');
       if (timers.length === 0) {
          hasVisibleTimers = false;
          return;
       }

       hasVisibleTimers = true;
       const now = new Date().getTime();

       timers.forEach(timer => {
          const deadlineStr = timer.getAttribute('data-deadline');
          if (!deadlineStr) return;

          // Parse YYYY-MM-DD HH:mm:ss format
          const parts = deadlineStr.split(/[- :]/);
          const deadline = new Date(
             parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]),
             parseInt(parts[3]), parseInt(parts[4]), parseInt(parts[5])
          ).getTime();
          const distance = deadline - now;

          if (distance < 0) {
             const overdue = Math.abs(distance);
             const oDays = Math.floor(overdue / (1000 * 60 * 60 * 24));
             const oHours = Math.floor((overdue % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
             const oMinutes = Math.floor((overdue % (1000 * 60 * 60)) / (1000 * 60));
             const oSeconds = Math.floor((overdue % (1000 * 60)) / 1000);

             let timeStr = '-';
             if (oDays > 0) timeStr += oDays + 'd ';
             timeStr += String(oHours).padStart(2, '0') + ':' + String(oMinutes).padStart(2, '0') + ':' + String(oSeconds).padStart(2, '0');

             timer.textContent = timeStr;
             timer.classList.remove('text-muted', 'text-warning', 'text-success');
             timer.classList.add('text-danger');
             return;
          }

          const days = Math.floor(distance / (1000 * 60 * 60 * 24));
          const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
          const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
          const seconds = Math.floor((distance % (1000 * 60)) / 1000);

          let timeStr = '';
          if (days > 0) timeStr += days + 'd ';
          timeStr += String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');

          timer.textContent = timeStr;

          timer.classList.remove('text-danger', 'text-warning', 'text-success');
          if (days === 0 && hours < 2) {
             timer.classList.add('text-warning');
          } else {
             timer.classList.add('text-success');
          }
       });
    }

/**
     * Show ticket detail modal
     */
    function showTicketDetail(ticket) {
       const modalEl = document.getElementById('kanbanTicketModal');
       const body = document.getElementById('kanbanTicketModalBody');
       const openLink = document.getElementById('kanbanTicketModalOpen');
       const ticketUrl = glpiRoot + '/front/ticket.form.php?id=' + ticket.id;

       if (openLink) {
          openLink.href = ticketUrl;
       }

       // Try to use pre-fetched ticket data, otherwise fetch from API
       if (ticket.date_mod && ticket.requester_name && ticket.status_name) {
          renderTicketDetail(ticket);
          const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
          modal.show();
          return;
       }

       // Fetch full ticket detail from API
       const params = new URLSearchParams();
       params.append('action', 'get_ticket_detail');
       params.append('ticket_id', ticket.id);
       params.append('_t', Date.now());

       fetch(apiUrl + '?' + params.toString())
          .then(response => response.json())
          .then(detail => {
             if (detail && detail.id) {
                renderTicketDetail(detail);
                const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                modal.show();
             } else {
                showToast('Could not load ticket details.', 'danger');
             }
          })
          .catch(err => {
             console.error('Error fetching ticket detail:', err);
             showToast('Error loading ticket details.', 'danger');
          });
    }

    function renderTicketDetail(ticket) {
       const body = document.getElementById('kanbanTicketModalBody');
       if (!body) return;

       const techNames = ticket.assigned_techs && ticket.assigned_techs.length > 0
          ? ticket.assigned_techs.join(', ')
          : lang.unassigned;

       const priorityBadge = `<span class="badge fs-8 text-nowrap px-2" style="background-color:${priorityColors[ticket.priority] || '#0d6efd'}; color:${(ticket.priority || 0) >= 4 ? '#000' : '#fff'}; min-width: 60px; justify-content: center;">${escapeHtml(ticket.priority_label || priorityLabels[ticket.priority] || 'Normal')}</span>`;

       let slaHtml = '';
       if (ticket.sla_progress && ticket.sla_progress.status !== 'no_sla' && ticket.time_to_resolve) {
          slaHtml = `
             <div class="mb-2">
                <strong>SLA Progress:</strong>
                <div class="progress" style="height: 8px;">
                   <div class="progress-bar bg-${ticket.sla_progress.color}" role="progressbar"
                        style="width: ${ticket.sla_progress.percent}%"
                        aria-valuenow="${ticket.sla_progress.percent}" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
                <small class="text-muted">${ticket.sla_progress.percent}% elapsed</small>
             </div>
          `;
       }

       const content = ticket.content || 'No description available.';

       body.innerHTML = `
           <div class="row g-0">
              <div class="col-md-6">
                 <div class="modal-detail-row">
                    <span class="modal-detail-label">Ticket #:</span>
                    <span class="modal-detail-value">${ticket.id}</span>
                 </div>
                 <div class="modal-detail-row">
                    <span class="modal-detail-label">Title:</span>
                    <span class="modal-detail-value text-end">${escapeHtml(ticket.title)}</span>
                 </div>
                 <div class="modal-detail-row">
                    <span class="modal-detail-label">Status:</span>
                    <span class="modal-detail-value"><span class="badge bg-secondary">${escapeHtml(ticket.status_name || '')}</span></span>
                 </div>
                 <div class="modal-detail-row">
                    <span class="modal-detail-label">Priority:</span>
                    <span class="modal-detail-value">${priorityBadge}</span>
                 </div>
                 <div class="modal-detail-row">
                    <span class="modal-detail-label">Category:</span>
                    <span class="modal-detail-value text-end">${escapeHtml(ticket.category || lang.noCategory)}</span>
                 </div>
                 <div class="modal-detail-row">
                    <span class="modal-detail-label">Requester:</span>
                    <span class="modal-detail-value text-end">${escapeHtml(ticket.requester_name || 'N/A')}</span>
                 </div>
                 <div class="modal-detail-row">
                    <span class="modal-detail-label">Technician:</span>
                    <span class="modal-detail-value text-end">${escapeHtml(techNames)}</span>
                 </div>
                 <div class="modal-detail-row">
                    <span class="modal-detail-label">Urgency:</span>
                    <span class="modal-detail-value text-end">${escapeHtml(ticket.urgency_label || '')}</span>
                 </div>
                 <div class="modal-detail-row">
                    <span class="modal-detail-label">Impact:</span>
                    <span class="modal-detail-value text-end">${escapeHtml(ticket.impact_label || '')}</span>
                 </div>
              </div>
              <div class="col-md-6">
                 <div class="modal-detail-row">
                    <span class="modal-detail-label">Opened:</span>
                    <span class="modal-detail-value text-end">${ticket.date_creation_formatted || ticket.date_creation}</span>
                 </div>
                 <div class="modal-detail-row">
                    <span class="modal-detail-label">Last Updated:</span>
                    <span class="modal-detail-value text-end">${ticket.date_mod || 'N/A'}</span>
                 </div>
                 <div class="modal-detail-row">
                    <span class="modal-detail-label">Action Time:</span>
                    <span class="modal-detail-value text-end">${ticket.actiontime ? formatDuration(ticket.actiontime) : 'N/A'}</span>
                 </div>
                 ${slaHtml}
              </div>
           </div>
           <div class="mt-3">
              <strong>Description:</strong>
              <div class="modal-description">
                 ${escapeHtml(content)}
              </div>
           </div>
        `;
    }

    function formatDuration(seconds) {
       const h = Math.floor(seconds / 3600);
       const m = Math.floor((seconds % 3600) / 60);
       return `${h}h ${m}m`;
    }

    // Fix timer interval leak - clear existing interval before starting new one
    function startTimerIfNeeded() {
       if (hasVisibleTimers && !timerInterval) {
          timerInterval = setInterval(updateCountdowns, 1000);
       } else if (!hasVisibleTimers && timerInterval) {
          clearInterval(timerInterval);
          timerInterval = null;
       }
    }

// =============================================
// Initialization
// =============================================

document.addEventListener('DOMContentLoaded', function () {
   renderBoardColumns();
   loadTickets();

   // Modal cleanup on close
   const modalEl = document.getElementById('kanbanTicketModal');
   if (modalEl) {
      modalEl.addEventListener('hidden.bs.modal', function () {
         const body = document.getElementById('kanbanTicketModalBody');
         if (body) {
            body.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>';
         }
      });
   }

   // Start countdown (will self-regulate based on visible timers)
   startTimerIfNeeded();
});

})();






