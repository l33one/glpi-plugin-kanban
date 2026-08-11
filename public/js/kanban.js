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
const lang = window.KANBAN_TRANSLATIONS || { open: 'Open', unassigned: 'Unassigned', noCategory: 'No Category', loading: 'Loading...', sortHint: 'Sort via column dropdowns', sortBy: 'Sort by', noTickets: 'No tickets', columns: 'Columns', hideColumn: 'Hide column', showColumn: 'Show column', showAllColumns: 'Show all columns', cardFields: 'Card fields', allTechnicians: 'All Technicians', allCategories: 'All Categories', searchByNumber: 'Search by ticket number', fieldPriority: 'Priority', fieldDateCreation: 'Opening date', fieldCategory: 'Category', fieldTechnician: 'Technician', fieldSla: 'SLA', fieldDuration: 'Open duration', ticketDuration: 'Open duration', slaFrozenHint: 'SLA paused' };

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

const bootstrapColorMap = { success: '#28a745', warning: '#ffc107', danger: '#dc3545', info: '#17a2b8', dark: '#343a40', primary: '#0d6efd', secondary: '#6c757d' };

// Hidden columns (per-user preference, persisted in localStorage)
let hiddenColumns = loadHiddenColumns();

// Card field visibility (per-user preference, persisted in localStorage)
const cardFields = {
   priority: { label: lang.fieldPriority, icon: 'ti-flag' },
   sla: { label: lang.fieldSla, icon: 'ti-clock' },
   assigned_techs: { label: lang.fieldTechnician, icon: 'ti-user' },
   category: { label: lang.fieldCategory, icon: 'ti-tag' },
   date_creation: { label: lang.fieldDateCreation, icon: 'ti-calendar' },
   duration: { label: lang.fieldDuration, icon: 'ti-hourglass' }
};
let hiddenCardFields = loadHiddenCardFields();

function loadHiddenCardFields() {
   try {
      const raw = localStorage.getItem('kanban_card_fields');
      return new Set(raw ? JSON.parse(raw) : []);
   } catch (e) {
      return new Set();
   }
}

function saveHiddenCardFields() {
   try {
      localStorage.setItem('kanban_card_fields', JSON.stringify(Array.from(hiddenCardFields)));
   } catch (e) { /* storage unavailable */ }
}

function loadHiddenColumns() {
   try {
      const raw = localStorage.getItem('kanban_hidden_columns');
      return new Set(raw ? JSON.parse(raw) : []);
   } catch (e) {
      return new Set();
   }
}

function saveHiddenColumns() {
   try {
      localStorage.setItem('kanban_hidden_columns', JSON.stringify(Array.from(hiddenColumns)));
   } catch (e) { /* storage unavailable */ }
}

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
                      <button type="button" class="btn btn-sm kanban-hide-btn" data-status-id="${id}" title="${escapeHtml(lang.hideColumn)}" aria-label="${escapeHtml(lang.hideColumn)}">
                         <i class="ti ti-eye-off"></i>
                      </button>
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
          const hideBtn = e.target.closest('.kanban-hide-btn');
          if (hideBtn) {
             e.preventDefault();
             toggleColumn(hideBtn.getAttribute('data-status-id'));
             return;
          }

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
             btn.innerHTML = '<i class="ti ti-arrows-up-down"></i><span class="visually-hidden">' + item.textContent.trim() + '</span>';
             btn.title = item.textContent.trim();
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
     * Toggle the visibility of a column (hide/show).
     */
    function toggleColumn(statusId) {
       if (!statusId) return;
       if (hiddenColumns.has(statusId)) {
          hiddenColumns.delete(statusId);
       } else {
          hiddenColumns.add(statusId);
       }
       saveHiddenColumns();
       applyColumnVisibility();
    }

    /**
     * Apply the hidden-column state to the board and to the columns menu.
     */
    function applyColumnVisibility() {
       document.querySelectorAll('.kanban-column').forEach(col => {
          const id = col.getAttribute('data-status-id');
          const hidden = hiddenColumns.has(id);
          col.classList.toggle('kanban-column-hidden', hidden);

          const hideBtn = col.querySelector('.kanban-hide-btn');
          if (hideBtn) {
             hideBtn.classList.toggle('is-hidden', hidden);
             const icon = hideBtn.querySelector('i');
             if (icon) {
                icon.className = hidden ? 'ti ti-eye' : 'ti ti-eye-off';
             }
             hideBtn.title = hidden ? lang.showColumn : lang.hideColumn;
             hideBtn.setAttribute('aria-label', hidden ? lang.showColumn : lang.hideColumn);
          }
       });

       document.querySelectorAll('.kanban-columns-menu .kanban-column-toggle').forEach(item => {
          const checkbox = item.querySelector('input[type="checkbox"]');
          if (checkbox) {
             checkbox.checked = !hiddenColumns.has(item.getAttribute('data-status-id'));
          }
       });
    }

    /**
     * Populate the "Columns" dropdown with a toggle per status.
     */
    function populateColumnsMenu() {
       const menu = document.querySelector('.kanban-columns-menu');
       if (!menu) return;

       const statuses = window.KANBAN_STATUSES || {};
       menu.innerHTML = '';

       const header = document.createElement('li');
       header.className = 'dropdown-header kanban-columns-header';
       header.textContent = lang.columns || 'Columns';
       menu.appendChild(header);

       for (const [id, status] of Object.entries(statuses)) {
          const li = document.createElement('li');
          const label = document.createElement('label');
          label.className = 'dropdown-item kanban-column-toggle';
          label.setAttribute('data-status-id', id);

          const checkbox = document.createElement('input');
          checkbox.type = 'checkbox';
          checkbox.checked = !hiddenColumns.has(id);

          const dot = document.createElement('span');
          dot.className = 'status-dot';
          dot.style.backgroundColor = bootstrapColorMap[status.color] || '#6c757d';

          const name = document.createElement('span');
          name.textContent = status.name || id;

          label.appendChild(checkbox);
          label.appendChild(dot);
          label.appendChild(name);
          li.appendChild(label);
          menu.appendChild(li);
       }

       const divider = document.createElement('li');
       divider.className = 'dropdown-divider';
       menu.appendChild(divider);

       const liAll = document.createElement('li');
       const aAll = document.createElement('a');
       aAll.className = 'dropdown-item kanban-columns-show-all';
       aAll.href = '#';
       aAll.textContent = lang.showAllColumns || 'Show all columns';
       liAll.appendChild(aAll);
       menu.appendChild(liAll);

       applyColumnVisibility();
    }

    /**
     * Populate the "Card fields" dropdown with a toggle per field.
     */
    function populateCardFieldsMenu() {
       const menu = document.querySelector('.kanban-fields-menu');
       if (!menu) return;

       menu.innerHTML = '';

       const header = document.createElement('li');
       header.className = 'dropdown-header kanban-fields-header';
       header.textContent = lang.cardFields || 'Card fields';
       menu.appendChild(header);

       for (const [key, field] of Object.entries(cardFields)) {
          const li = document.createElement('li');
          const label = document.createElement('label');
          label.className = 'dropdown-item kanban-field-toggle';
          label.setAttribute('data-field', key);

          const checkbox = document.createElement('input');
          checkbox.type = 'checkbox';
          checkbox.checked = !hiddenCardFields.has(key);

          const icon = document.createElement('i');
          icon.className = 'ti ' + (field.icon || 'ti-info-circle');

          const name = document.createElement('span');
          name.textContent = field.label;

          label.appendChild(checkbox);
          label.appendChild(icon);
          label.appendChild(name);
          li.appendChild(label);
          menu.appendChild(li);
       }
    }

    /**
     * Toggle the visibility of a card field and re-render the board.
     */
    function toggleCardField(field) {
       if (!field) return;
       if (hiddenCardFields.has(field)) {
          hiddenCardFields.delete(field);
       } else {
          hiddenCardFields.add(field);
       }
       saveHiddenCardFields();
       applyCardFieldMenuState();
       rerenderCards();
    }

    /**
     * Sync the checkboxes in the "Card fields" menu with the current state.
     */
    function applyCardFieldMenuState() {
       document.querySelectorAll('.kanban-fields-menu .kanban-field-toggle').forEach(item => {
          const checkbox = item.querySelector('input[type="checkbox"]');
          if (checkbox) {
             checkbox.checked = !hiddenCardFields.has(item.getAttribute('data-field'));
          }
       });
    }

    /**
     * Re-render all existing cards in place (used after a field is toggled).
     */
    function rerenderCards() {
       for (const [statusId, tickets] of Object.entries(ticketsByStatus)) {
          const zone = document.getElementById('status-column-' + statusId);
          if (!zone) continue;
          if (tickets.length === 0) {
             showEmptyPlaceholder(zone, statusId);
             continue;
          }
           const sorted = sortTicketsByValue(tickets, columnSorts[statusId] || 'date_DESC');
           zone.innerHTML = '';
           sorted.forEach(ticket => {
              zone.appendChild(createCardElement(ticket));
           });
        }
        updateCountdowns();
        startTimerIfNeeded();
     }

    /**
     * Rebuild the technician filter options, refreshing the select2 widget.
     */
    function populateTechnicianSelect(techs) {
       const sel = document.getElementById('filter-technician');
       if (!sel) return;

       const current = sel.value;
       sel.innerHTML = '';

       const optAll = document.createElement('option');
       optAll.value = '';
       optAll.textContent = lang.allTechnicians || 'All Technicians';
       sel.appendChild(optAll);

       techs.forEach(t => {
          const opt = document.createElement('option');
          opt.value = t.id;
          opt.textContent = t.name;
          sel.appendChild(opt);
       });

       // Keep the current selection if it is still valid, otherwise clear it
       if (techs.some(t => String(t.id) === String(current))) {
          sel.value = current;
       } else {
          sel.value = '';
       }

       if (window.$ && window.$.fn && typeof window.$.fn.select2 === 'function') {
          try {
             window.$(sel).trigger('change');
          } catch (e) { /* select2 refresh failed, native select still works */ }
       }
    }

    /**
     * Handle group filter change: restrict the technician dropdown to the
     * members of the selected group (and its subgroups).
     */
    function bindGroupTechnicianFilter() {
       const groupSel = document.getElementById('filter-group');
       if (!groupSel) return;

       groupSel.addEventListener('change', function () {
          const groupId = groupSel.value;
          if (!groupId) {
             populateTechnicianSelect(window.KANBAN_TECHNICIANS || []);
             return;
          }

          fetch(apiUrl + '?action=get_group_technicians&group=' + encodeURIComponent(groupId) + '&_t=' + Date.now())
             .then(response => response.json())
             .then(res => {
                if (res && Array.isArray(res.technicians)) {
                   populateTechnicianSelect(res.technicians);
                }
             })
             .catch(err => {
                console.error('Error loading group technicians:', err);
             });
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

              // Reset every column: clear cards and zero out the counts so stale
              // numbers from a previous (e.g. group-filtered) load never linger.
              document.querySelectorAll('.kanban-column').forEach(col => {
                 const id = col.getAttribute('data-status-id');
                 const zone = document.getElementById('status-column-' + id);
                 const countBadge = document.getElementById('count-' + id);
                 if (zone) zone.innerHTML = '';
                 if (countBadge) countBadge.textContent = '0';
              });

              // Populate columns
              let totalTickets = 0;
              const statusesWithData = new Set();
              for (const [statusId, tickets] of Object.entries(data)) {
                 const zone = document.getElementById('status-column-' + statusId);
                 const countBadge = document.getElementById('count-' + statusId);

                 if (countBadge) {
                    countBadge.textContent = tickets.length;
                 }

                 if (zone && tickets.length > 0) {
                    statusesWithData.add(statusId);
                    const sorted = sortTicketsByValue(tickets, columnSorts[statusId] || 'date_DESC');
                    sorted.forEach(ticket => {
                       zone.appendChild(createCardElement(ticket));
                    });
                    totalTickets += tickets.length;
                 }
              }

              // Columns that did not appear in the response keep an empty placeholder
              document.querySelectorAll('.kanban-column').forEach(col => {
                 const id = col.getAttribute('data-status-id');
                 if (!statusesWithData.has(id)) {
                    const zone = document.getElementById('status-column-' + id);
                    if (zone) showEmptyPlaceholder(zone, id);
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
              case 'sla':
                 // Sort by SLA progress (percent elapsed); tickets without SLA
                 // always sink to the bottom of the column.
                 cmp = slaSortKey(b) - slaSortKey(a);
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
      * Numeric sort key for SLA progress: percent elapsed, or Infinity when the
      * ticket has no SLA (so it is ordered last in either direction).
      */
     function slaSortKey(ticket) {
        if (ticket.sla_progress && ticket.sla_progress.status !== 'no_sla') {
           return ticket.sla_progress.percent || 0;
        }
        return Infinity;
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
       if (!hiddenCardFields.has('sla') && ticket.sla_progress && ticket.sla_progress.status !== 'no_sla' && ticket.time_to_resolve) {
          const slaFrozen = !!ticket.sla_progress.frozen;
          let timerHtml = `<span class="sla-countdown-timer fw-bold" data-deadline="${ticket.time_to_resolve}">--:--:--</span>`;
          if (slaFrozen) {
             // Solved/closed/pending tickets: the SLA clock is stopped. Render a
             // static countdown computed at the freeze reference so it never ticks.
             let staticText = '--:--:--';
             if (ticket.sla_progress.frozen_at) {
                staticText = formatCountdown(parseGlpiDateTime(ticket.time_to_resolve) - parseGlpiDateTime(ticket.sla_progress.frozen_at));
             }
             timerHtml = `<span class="sla-countdown-timer sla-countdown-frozen fw-bold" title="${escapeHtml(lang.slaFrozenHint)}">${staticText}</span>`;
          } else {
             hasVisibleTimers = true;
          }
          slaBarHtml = `
             <div class="sla-progress mb-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                   <span class="d-flex align-items-center gap-1">
                      <i class="ti ti-clock"></i>
                      ${timerHtml}
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
       }

       // Technicians text
       const techNames = ticket.assigned_techs && ticket.assigned_techs.length > 0
          ? ticket.assigned_techs.join(', ')
          : lang.unassigned;

       // Priority badge
       const priorityBadge = `<span class="badge fs-8 text-nowrap px-2" style="background-color:${priorityColors[ticket.priority] || '#0d6efd'}; color:${(ticket.priority || 0) >= 4 ? '#000' : '#fff'}; min-width: 60px; justify-content: center;">${priorityLabels[ticket.priority] || 'Normal'}</span>`;

       // Construct the ticket URL
       const ticketUrl = glpiRoot + '/front/ticket.form.php?id=' + ticket.id;

       // Card fields that can be toggled from the "Card fields" menu
       const showPriority = !hiddenCardFields.has('priority');
       const showTechs = !hiddenCardFields.has('assigned_techs');
       const showCategory = !hiddenCardFields.has('category');
       const showDate = !hiddenCardFields.has('date_creation');
       const showDuration = !hiddenCardFields.has('duration');
       const isPending = String(ticket.status) === '4';

       const topActions = [];
       if (showPriority) topActions.push(priorityBadge);

       const metaItems = [];
       if (showTechs) {
          metaItems.push(`<div class="card-meta-item text-truncate" title="${escapeHtml(techNames)}">
             <i class="ti ti-user"></i><span>${escapeHtml(techNames)}</span>
          </div>`);
       }
       if (showCategory) {
          metaItems.push(`<div class="card-meta-item text-truncate" title="${escapeHtml(ticket.category || lang.noCategory)}">
             <i class="ti ti-tag"></i><span>${escapeHtml(ticket.category || lang.noCategory)}</span>
          </div>`);
       }
       if (isPending && showDuration) {
          // Pending tickets: the SLA clock is paused, but the open duration
          // keeps running, so a live elapsed-time counter is shown.
          metaItems.push(`<div class="card-meta-item text-truncate" title="${escapeHtml(lang.ticketDuration)}">
             <i class="ti ti-hourglass"></i><span class="kanban-duration-timer" data-created="${ticket.date_creation}">--:--:--</span>
          </div>`);
       }
       if (showDate) {
          metaItems.push(`<div class="card-meta-item ms-auto" title="${ticket.date_creation}">
             <i class="ti ti-calendar"></i><span>${ticket.date_creation}</span>
          </div>`);
       }

       card.innerHTML = `
          <div class="card-body">
             <div class="kanban-card-top">
                <span class="ticket-id">#${ticket.id}</span>
                <div class="d-flex align-items-center gap-1 flex-shrink-0">
                   ${topActions.join('')}
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
             ${metaItems.length > 0 ? '<div class="card-meta">' + metaItems.join('') + '</div>' : ''}
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

   // Ticket number search field (live, debounced)
   const ticketIdInput = document.getElementById('filter-ticket-id');
   if (ticketIdInput) {
      ticketIdInput.addEventListener('input', debouncedLoadTickets);
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

   /**
    * Parse a GLPI "YYYY-MM-DD HH:mm:ss" string into a JS timestamp.
    */
   function parseGlpiDateTime(str) {
      if (!str) return 0;
      const parts = String(str).split(/[- :]/);
      return new Date(
         parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]),
         parseInt(parts[3]), parseInt(parts[4]), parseInt(parts[5])
      ).getTime();
   }

   /**
    * Format a remaining/overdue countdown (negative distance = overdue).
    */
   function formatCountdown(distanceMs) {
      if (distanceMs < 0) {
         const overdue = Math.abs(distanceMs);
         const oDays = Math.floor(overdue / (1000 * 60 * 60 * 24));
         const oHours = Math.floor((overdue % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
         const oMinutes = Math.floor((overdue % (1000 * 60 * 60)) / (1000 * 60));
         const oSeconds = Math.floor((overdue % (1000 * 60)) / 1000);

         let timeStr = '-';
         if (oDays > 0) timeStr += oDays + 'd ';
         timeStr += String(oHours).padStart(2, '0') + ':' + String(oMinutes).padStart(2, '0') + ':' + String(oSeconds).padStart(2, '0');
         return timeStr;
      }

      const days = Math.floor(distanceMs / (1000 * 60 * 60 * 24));
      const hours = Math.floor((distanceMs % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
      const minutes = Math.floor((distanceMs % (1000 * 60 * 60)) / (1000 * 60));
      const seconds = Math.floor((distanceMs % (1000 * 60)) / 1000);

      let timeStr = '';
      if (days > 0) timeStr += days + 'd ';
      timeStr += String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
      return timeStr;
   }

   /**
    * Format an elapsed (positive) duration, e.g. the open time of a ticket.
    */
   function formatElapsed(ms) {
      return formatCountdown(Math.max(0, ms));
   }

function updateCountdowns() {
       const timers = document.querySelectorAll('.sla-countdown-timer[data-deadline]');
       const durations = document.querySelectorAll('.kanban-duration-timer');

       if (timers.length === 0 && durations.length === 0) {
          hasVisibleTimers = false;
          return;
       }

       hasVisibleTimers = true;
       const now = new Date().getTime();

       timers.forEach(timer => {
          const deadlineStr = timer.getAttribute('data-deadline');
          if (!deadlineStr) return;

          const distance = parseGlpiDateTime(deadlineStr) - now;
          timer.textContent = formatCountdown(distance);
          timer.classList.remove('text-muted', 'text-warning', 'text-success');

          if (distance < 0) {
             timer.classList.add('text-danger');
             return;
          }

          const days = Math.floor(distance / (1000 * 60 * 60 * 24));
          const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
          if (days === 0 && hours < 2) {
             timer.classList.add('text-warning');
          } else {
             timer.classList.add('text-success');
          }
       });

       durations.forEach(dur => {
          const createdStr = dur.getAttribute('data-created');
          if (!createdStr) return;
          dur.textContent = formatElapsed(now - parseGlpiDateTime(createdStr));
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
                  ${content}
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
       const needsTimer =
          document.querySelectorAll('.sla-countdown-timer[data-deadline]').length > 0 ||
          document.querySelectorAll('.kanban-duration-timer').length > 0;

       if (needsTimer && !timerInterval) {
          timerInterval = setInterval(updateCountdowns, 1000);
       } else if (!needsTimer && timerInterval) {
          clearInterval(timerInterval);
          timerInterval = null;
       }
    }

// =============================================
// Initialization
// =============================================

document.addEventListener('DOMContentLoaded', function () {
   renderBoardColumns();
   populateColumnsMenu();
   populateCardFieldsMenu();
   applyColumnVisibility();
   bindGroupTechnicianFilter();
   loadTickets();

   // Columns visibility menu (may live outside the board container)
   document.addEventListener('click', function (e) {
      const toggle = e.target.closest('.kanban-column-toggle');
      if (toggle) {
         e.preventDefault();
         toggleColumn(toggle.getAttribute('data-status-id'));
         return;
      }
      const showAll = e.target.closest('.kanban-columns-show-all');
      if (showAll) {
         e.preventDefault();
         hiddenColumns.clear();
         saveHiddenColumns();
         applyColumnVisibility();
      }
      const fieldToggle = e.target.closest('.kanban-field-toggle');
      if (fieldToggle) {
         e.preventDefault();
         toggleCardField(fieldToggle.getAttribute('data-field'));
      }
   });

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






