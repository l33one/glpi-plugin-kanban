/**
 * GLPI Kanban Plugin - JS Controller
 */
(function () {
   'use strict';

   // Lazy DOM references – resolved on first access so the script can load
   // from <head> (via $PLUGIN_HOOKS['add_javascript']) without needing the
   // body elements to exist yet.
   var _filterForm = undefined, _boardContainer = undefined, _loadingOverlay = undefined, _csrfToken = undefined;
   function getFilterForm()      { if (_filterForm === undefined || (_filterForm === null && document.getElementById('kanban-filter-form'))) _filterForm = document.getElementById('kanban-filter-form'); return _filterForm; }
   function getBoardContainer()  { if (_boardContainer === undefined || (_boardContainer === null && document.getElementById('kanban-board'))) _boardContainer = document.getElementById('kanban-board'); return _boardContainer; }
   function getLoadingOverlay()  { if (_loadingOverlay === undefined || (_loadingOverlay === null && document.getElementById('kanban-loading-overlay'))) _loadingOverlay = document.getElementById('kanban-loading-overlay'); return _loadingOverlay; }
   function getCsrfToken() {
      if (_csrfToken === undefined || _csrfToken === '') {
         var el = document.querySelector('input[name="_glpi_csrf_token"]');
         if (el) _csrfToken = el.value;
      }
      return _csrfToken || '';
   }

// State
let timerInterval = null;
let hasVisibleTimers = false;
let ticketsByStatus = {};
let columnSorts = {};
const lang = window.KANBAN_TRANSLATIONS || { open: 'Open', unassigned: 'Unassigned', noCategory: 'No Category', loading: 'Loading...', sortHint: 'Sort via column dropdowns', sortBy: 'Sort by', noTickets: 'No tickets', columns: 'Columns', hideColumn: 'Hide column', showColumn: 'Show column', showAllColumns: 'Show all columns', cardFields: 'Card fields', allTechnicians: 'All Technicians', allCategories: 'All Categories', noTechnician: 'No Technician', noRequester: 'No Requester', noGroup: 'No Group', noType: 'No Type', noCategoryFilter: 'No Category', searchByNumber: 'Search by ticket number', searchPlaceholder: 'Search...', advancedFilters: 'Advanced Filters', assignedToMe: 'Assigned to me', clearFilters: 'Clear', quickActions: 'Quick actions', assignToMe: 'Assign to me', listFollowups: 'List follow-ups', changePriority: 'Change priority', followupsTitle: 'Follow-ups', noFollowups: 'No follow-ups', metricTotal: 'Visible tickets', metricSlaOnTime: 'Within SLA', metricSlaOverdue: 'SLA overdue', metricAssignedToMe: 'Assigned to me', metricUnassigned: 'Unassigned', saved: 'Saved', assigned: 'Assigned', justNow: 'just now', minutesAgo: 'X min ago', hoursAgo: 'X h ago', daysAgo: 'X d ago', today: 'Today', yesterday: 'Yesterday', tomorrow: 'Tomorrow', inDays: 'in X d', fieldPriority: 'Priority', fieldDateCreation: 'Opening date', fieldCategory: 'Category', fieldTechnician: 'Technician', fieldSla: 'SLA', fieldDuration: 'Open duration', ticketDuration: 'Open duration', slaFrozenHint: 'SLA paused', statusUpdated: 'Status updated', moveTo: 'Move to', cancel: 'Cancel', confirmMove: 'Move', pendingReason: 'Pending reason', pendingReasonPlaceholder: 'Describe why the ticket is pending...', solutionDescription: 'Solution', solutionModel: 'Solution model', solutionType: 'Solution type', noSolutionModel: 'Select a model...', noSolutionType: 'No type', moveToPending: 'Move to Pending', moveToSolved: 'Move to Solved', followupSkipped: 'The status changed, but the pending reason was not recorded (no follow-up right).', solutionSkipped: 'The status changed, but the solution was not recorded (the ticket can no longer be solved).' };

// Current logged-in user (id + name), injected server-side
const currentUser = window.KANBAN_CURRENT_USER || { id: 0, name: '' };

// Drag & drop support, injected server-side from the plugin configuration.
const enableDragDrop = window.KANBAN_ENABLE_DRAG_DROP ? true : false;
const requirePendingReason = window.KANBAN_REQUIRE_PENDING_REASON ? true : false;
const requireSolutionModel = window.KANBAN_REQUIRE_SOLUTION_MODEL ? true : false;
const requireSolutionType = window.KANBAN_REQUIRE_SOLUTION_TYPE ? true : false;
const solutionTemplates = window.KANBAN_SOLUTION_TEMPLATES || [];
const solutionTypes = window.KANBAN_SOLUTION_TYPES || [];
const pendingReasons = window.KANBAN_PENDING_REASONS || [];

// Values/labels mirror GLPI's PendingReason widget ("Automatic follow-up" and
// "Automatic resolution" selectors), sharing the same option values so saved
// data remains interchangeable with the native ticket form.
const pendingFrequencyOptions = [
   { value: 0,       label: lang.pendingFreqDisabled || 'Automatic follow-up disabled' },
   { value: 86400,   label: 'Every day' },
   { value: 172800,  label: 'Every two days' },
   { value: 259200,  label: 'Every three days' },
   { value: 345600,  label: 'Every four days' },
   { value: 432000,  label: 'Every five days' },
   { value: 518400,  label: 'Every six days' },
   { value: 604800,  label: 'Every week' },
   { value: 1209600, label: 'Every two weeks' },
   { value: 1814400, label: 'Every three weeks' },
   { value: 2419200, label: 'Every four weeks' }
];
const pendingResolutionOptions = [
   { value: 0, label: lang.pendingResDisabled || 'Disabled' },
   { value: 1, label: 'After one follow-up' },
   { value: 2, label: 'After two follow-ups' },
   { value: 3, label: 'After three follow-ups' }
];

// State of the in-flight drag (null when idle).
let dragState = null; // { ticketId, fromStatus }

// Pending status transition awaiting the (optional) modal confirmation.
let transitionAction = null; // { ticketId, toStatus }

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
       var lo = getLoadingOverlay();
       if (lo) { lo.style.display = 'flex'; }
   }

   function hideLoading() {
       var lo = getLoadingOverlay();
       if (lo) { lo.style.display = 'none'; }
   }

    /**
     * Escape HTML entities.
     * Coerces the value to a string so numeric inputs cannot throw a TypeError.
     */
    function escapeHtml(str) {
       if (str === null || str === undefined) return '';
       str = String(str);
       const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' };
       return str.replace(/[&<>'"]/g, tag => map[tag] || tag);
    }

    /**
     * Rich-text content (ticket descriptions, follow-ups) is sanitized
     * SERVER-SIDE with GLPI's RichText::getSafeHtml() before it is sent to the
     * browser. It is therefore safe to insert directly as HTML — no
     * client-side sanitizer is needed (hand-rolled sanitizers are too easy to
     * bypass with SVG/mutation-XSS payloads).
     */

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
       if (!getBoardContainer()) return;

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
       getBoardContainer().innerHTML = boardHtml;

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
        getBoardContainer().addEventListener('click', function(e) {
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

        recalculateVisibleMetrics();
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
           checkbox.addEventListener('change', function () {
              if (this.checked) {
                 hiddenColumns.delete(id);
              } else {
                 hiddenColumns.add(id);
              }
              saveHiddenColumns();
              applyColumnVisibility();
           });

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
           checkbox.addEventListener('change', function () {
              if (this.checked) {
                 hiddenCardFields.delete(key);
              } else {
                 hiddenCardFields.add(key);
              }
              saveHiddenCardFields();
              rerenderCards();
           });

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

        const optNone = document.createElement('option');
        optNone.value = '0';
        optNone.textContent = lang.noTechnician || 'No Technician';
        sel.appendChild(optNone);

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
     * Count the active secondary filters and show it in the "Advanced Filters" badge.
     */
    function updateAdvancedFilterCount() {
       const badge = document.getElementById('kanban-adv-count');
       if (!badge) return;
       let count = 0;
        ['filter-technician', 'filter-requester', 'filter-group', 'filter-type', 'filter-category'].forEach(id => {
           const el = document.getElementById(id);
           if (el && el.value !== '' && el.value !== null && el.value !== undefined) count++;
       });
       if (count > 0) {
          badge.textContent = count;
          badge.classList.remove('d-none');
       } else {
          badge.classList.add('d-none');
       }
    }

    /**
     * Set the technician filter to a specific user, adding the option if missing.
     */
    function setTechnicianFilter(userId, userName) {
       const sel = document.getElementById('filter-technician');
       if (!sel) return;
       if (!userId) return;

       let opt = sel.querySelector('option[value="' + String(userId) + '"]');
       if (!opt) {
          opt = document.createElement('option');
          opt.value = userId;
          opt.textContent = userName || ('#' + userId);
          sel.appendChild(opt);
       }
       sel.value = String(userId);
       if (window.$ && window.$.fn && typeof window.$.fn.select2 === 'function') {
          try { window.$(sel).trigger('change'); } catch (e) { /* select2 refresh */ }
       }
       updateAdvancedFilterCount();
       loadTickets();
    }

    /**
     * Toggle the quick-actions dropdown of a card.
     */
    function toggleCardMenu(btn) {
       if (!btn) return;
       const menu = btn.parentElement.querySelector('.kanban-card-menu');
       if (!menu) return;
       const open = menu.classList.contains('show');
       document.querySelectorAll('.kanban-card-menu.show').forEach(m => m.classList.remove('show'));
       menu.classList.toggle('show', !open);
       btn.setAttribute('aria-expanded', String(!open));
    }

    function closeCardMenus() {
       document.querySelectorAll('.kanban-card-menu.show').forEach(m => m.classList.remove('show'));
       document.querySelectorAll('.kanban-card-menu-btn[aria-expanded="true"]').forEach(b => b.setAttribute('aria-expanded', 'false'));
    }

    /**
     * Quick action: assign the current user as technician to a ticket.
     */
     function assignTicketToMe(ticketId) {
        const body = new URLSearchParams();
        body.append('action', 'assign_to_me');
        body.append('ticket_id', ticketId);
       if (getCsrfToken()) body.append('_glpi_csrf_token', getCsrfToken());

        fetch(apiUrl, { method: 'POST', body: body, headers: { 'Content-Type': 'application/x-www-form-urlencoded' } })
           .then(r => r.json())
           .then(res => {
              if (res && res.csrf_token) {
                 _csrfToken = res.csrf_token;
              }
             const result = res && res.result ? res.result : res;
             if (result && result.success) {
                showToast(lang.assigned + ' #' + ticketId, 'success');
                loadTickets();
             } else {
                showToast((result && result.error) || 'Error', 'danger');
             }
          })
          .catch(() => showToast('Error assigning ticket', 'danger'));
    }

    /**
     * Quick action: show the follow-ups of a ticket in a modal.
     */
    function showFollowups(ticketId) {
       const modalEl = document.getElementById('kanbanFollowupsModal');
       const body = document.getElementById('kanbanFollowupsModalBody');
       if (!modalEl || !body) return;

       const label = document.getElementById('kanbanFollowupsModalLabel');
       if (label) label.textContent = (lang.followupsTitle || 'Follow-ups') + ' #' + ticketId;

       body.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>';
       const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
       modal.show();

       fetch(apiUrl + '?action=get_followups&ticket_id=' + encodeURIComponent(ticketId) + '&_t=' + Date.now())
          .then(r => r.json())
          .then(res => {
             const list = (res && Array.isArray(res.followups)) ? res.followups : [];
             if (list.length === 0) {
                body.innerHTML = '<div class="text-center text-muted py-4"><i class="ti ti-message-off fs-2 d-block mb-2"></i>' + escapeHtml(lang.noFollowups || 'No follow-ups') + '</div>';
                return;
             }
             body.innerHTML = list.map(fu => `
                <div class="kanban-followup">
                   <div class="d-flex justify-content-between align-items-center gap-2 mb-1">
                      <span class="fw-semibold">${escapeHtml(fu.user)}</span>
                      <small class="text-muted" title="${escapeHtml(fu.date)}">${escapeHtml(relativeDate(fu.date))}</small>
                   </div>
                   <div class="kanban-followup-content">${fu.content || ''}</div>
                </div>
             `).join('');
          })
          .catch(() => {
             body.innerHTML = '<div class="text-center text-danger py-4">Error loading follow-ups.</div>';
          });
    }

    /**
     * Quick action: change the priority of a ticket.
     */
     function setTicketPriority(ticketId, priority) {
        const body = new URLSearchParams();
        body.append('action', 'change_priority');
        body.append('ticket_id', ticketId);
        body.append('priority', priority);
       if (getCsrfToken()) body.append('_glpi_csrf_token', getCsrfToken());

        fetch(apiUrl, { method: 'POST', body: body, headers: { 'Content-Type': 'application/x-www-form-urlencoded' } })
           .then(r => r.json())
           .then(res => {
              if (res && res.csrf_token) {
                 _csrfToken = res.csrf_token;
              }
             const result = res && res.result ? res.result : res;
             if (result && result.success) {
                showToast(lang.saved, 'success');
                closeCardMenus();
                loadTickets();
             } else {
                showToast((result && result.error) || 'Error', 'danger');
             }
          })
          .catch(() => showToast('Error updating priority', 'danger'));
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
        if (!getFilterForm()) return;

       // Clear existing timer to prevent leaks
       if (timerInterval) {
          clearInterval(timerInterval);
          timerInterval = null;
       }
       hasVisibleTimers = false;

       showLoading();

        const formData = new FormData(getFilterForm());
       const params = new URLSearchParams();
       params.append('action', 'get_tickets');
       params.append('_t', Date.now());

       for (const [key, value] of formData.entries()) {
           if (value !== '' && value !== null && value !== undefined) {
              params.append(key, value);
           }
        }

       fetch(apiUrl + '?' + params.toString())
          .then(response => {
             if (!response.ok) throw new Error('HTTP ' + response.status);
             return response.json();
          })
.then(data => {
               const statusData = data.statuses || data;
               ticketsByStatus = statusData;

               // Refresh the "gestão à vista" indicators bar
               updateMetrics(data.metrics || {});

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
              for (const [statusId, tickets] of Object.entries(statusData)) {
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

// Update total tickets counter in header. Columns hidden via the
               // board menu (persisted in localStorage) are excluded, so the
               // badge and the "visible tickets" indicator always match the
               // columns actually shown.
               if (hiddenColumns.size > 0) {
                  recalculateVisibleMetrics();
               } else {
                  const totalBadge = document.getElementById('kanban-total');
                  if (totalBadge) {
                     totalBadge.textContent = totalTickets;
                  }
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
     * Update the "gestão à vista" indicators bar with server-side metrics.
     */
    function updateMetrics(metrics) {
       const set = (id, text, colorClass) => {
          const el = document.getElementById(id);
          if (!el) return;
          el.textContent = text;
          if (colorClass) {
             el.classList.remove('text-success', 'text-danger', 'text-warning');
             el.classList.add(colorClass);
          }
       };
       set('metric-total', metrics.total != null ? metrics.total : 0);
       set('metric-sla', metrics.sla_percent != null ? metrics.sla_percent + '%' : '--');
       set('metric-overdue', metrics.sla_overdue != null ? metrics.sla_overdue : 0,
          (metrics.sla_overdue || 0) > 0 ? 'text-danger' : null);
       set('metric-assigned', metrics.assigned_to_me != null ? metrics.assigned_to_me : 0);
        set('metric-unassigned', metrics.unassigned != null ? metrics.unassigned : 0,
           (metrics.unassigned || 0) > 0 ? 'text-warning' : null);
    }

    /**
     * Recalculate metrics from client-side tickets, excluding hidden columns.
     * Called after toggling column visibility so the metrics bar always reflects
     * the currently visible board state.
     */
    function recalculateVisibleMetrics() {
       let total = 0, withSla = 0, slaOnTime = 0, slaOverdue = 0;
       let assignedToMe = 0, unassigned = 0;
       const userId = currentUser.id;

       for (const [statusId, tickets] of Object.entries(ticketsByStatus)) {
          if (hiddenColumns.has(statusId)) continue;
          for (const ticket of tickets) {
             total++;
             const sla = ticket.sla_progress || null;
             if (sla && sla.status && sla.status !== 'no_sla') {
                withSla++;
                if (sla.status === 'overdue' || (sla.percent || 0) >= 100) {
                   slaOverdue++;
                } else {
                   slaOnTime++;
                }
             }
             const techs = ticket.assigned_techs || [];
             if (userId > 0 && techs.length > 0) {
                for (const tech of techs) {
                   if (parseInt(tech.id) === userId) {
                      assignedToMe++;
                      break;
                   }
                }
             }
             if (techs.length === 0) {
                unassigned++;
             }
          }
       }

       const slaPercent = withSla > 0 ? Math.round((slaOnTime / withSla) * 100) : 100;
       updateMetrics({
          total: total,
          sla_percent: slaPercent,
          sla_overdue: slaOverdue,
          assigned_to_me: assignedToMe,
          unassigned: unassigned,
       });

       const totalBadge = document.getElementById('kanban-total');
       if (totalBadge) {
          totalBadge.textContent = total;
       }
    }

    /**
     * Format a GLPI datetime as a short relative label ("Today", "Yesterday",
     * "3 d ago", "Tomorrow", "in 2 d").
     */
function relativeDate(dateStr) {
       if (!dateStr) return '';
       const DAY = 86400000;
       const ts = parseGlpiDateTime(dateStr);
       if (!ts) return dateStr;
       const now = new Date();
       const startOfDay = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
       const dayDiff = Math.round((startOfDay(new Date(ts)) - startOfDay(now)) / DAY);

       if (dayDiff === 0) {
          const mins = Math.round((now.getTime() - ts) / 60000);
          if (mins <= 0) return lang.today || 'Today';
          if (mins < 60) return (lang.minutesAgo || 'X min ago').replace('X', mins);
          const hours = Math.floor(mins / 60);
          return (lang.hoursAgo || 'X h ago').replace('X', hours);
       }
if (dayDiff === -1) return lang.yesterday || 'Yesterday';
       if (dayDiff === 1) return lang.tomorrow || 'Tomorrow';
       if (dayDiff < -1) return (lang.daysAgo || 'X d ago').replace('X', Math.abs(dayDiff));
       return (lang.inDays || 'in X d').replace('X', dayDiff);
    }

    /**
     * Compute compact initials for a technician avatar.
     */
    function getInitials(tech) {
       const first = (tech.firstname || '').trim();
       const last = (tech.realname || '').trim();
       let initials = '';
       if (first) initials += first.charAt(0);
       if (last) initials += last.charAt(0);
       return (initials || (tech.name || '?').charAt(0)).toUpperCase();
    }

    /**
     * Deterministic avatar background color from a user id.
     */
    function avatarColor(id) {
       const palette = ['#2563eb', '#0d9488', '#7c3aed', '#d97706', '#dc2626', '#0891b2', '#4f46e5', '#be185d'];
       const n = Math.abs(parseInt(id, 10) || 0);
       return palette[n % palette.length];
    }

    /**
     * Compact avatar (photo or initials) for an assigned technician.
     */
    function techAvatarHtml(tech) {
       const img = tech.picture
          ? '<img src="' + escapeHtml(tech.picture) + '" alt="" loading="lazy">'
          : '<span>' + escapeHtml(getInitials(tech)) + '</span>';
       return '<span class="kanban-avatar" style="background-color:' + avatarColor(tech.id) + '" title="' + escapeHtml(tech.name) + '">' + img + '</span>';
    }

    /**
     * Build the quick-actions dropdown shown on card hover.
     */
    function buildQuickActionsHtml(ticket) {
       const priorityOptions = Object.keys(priorityLabels).map(p => {
          const cls = (ticket.priority || 0) === Number(p) ? ' active' : '';
          return '<button type="button" class="kanban-prio-opt' + cls + '" data-priority="' + p + '" style="background-color:' + (priorityColors[p] || '#6c757d') + '" title="' + escapeHtml(priorityLabels[p]) + '" aria-label="' + escapeHtml(priorityLabels[p]) + '"></button>';
       }).join('');

       return `
          <div class="kanban-quick-actions">
             <button type="button" class="kanban-card-menu-btn" title="${escapeHtml(lang.quickActions || 'Quick actions')}" aria-label="${escapeHtml(lang.quickActions || 'Quick actions')}" aria-haspopup="true" aria-expanded="false">
                <i class="ti ti-dots-vertical"></i>
             </button>
             <div class="kanban-card-menu dropdown-menu dropdown-menu-end" role="menu">
                <button type="button" class="dropdown-item kanban-act-assign" role="menuitem">
                   <i class="ti ti-user-check"></i> ${escapeHtml(lang.assignToMe || 'Assign to me')}
                </button>
                <button type="button" class="dropdown-item kanban-act-followups" role="menuitem">
                   <i class="ti ti-messages"></i> ${escapeHtml(lang.listFollowups || 'List follow-ups')}
                </button>
                <div class="dropdown-divider"></div>
                <div class="kanban-sort-header">${escapeHtml(lang.changePriority || 'Change priority')}</div>
                <div class="kanban-priority-picker">${priorityOptions}</div>
             </div>
          </div>
       `;
    }

   /**
    * Create HTML card element for a Ticket
    */
function createCardElement(ticket) {
       const card = document.createElement('div');
       card.className = 'kanban-card card';
              card.setAttribute('data-ticket-id', ticket.id);
                     card.setAttribute('aria-label', 'Ticket #' + ticket.id + ' - ' + (ticket.title || ''));

       if (enableDragDrop) {
          card.setAttribute('draggable', 'true');
          card.classList.add('kanban-card-draggable');
       }

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

       // Technicians: compact avatars (photo or initials) instead of truncated text
       const techs = ticket.assigned_techs && ticket.assigned_techs.length > 0 ? ticket.assigned_techs : [];
       const techNames = techs.length > 0 ? techs.map(t => t.name).join(', ') : lang.unassigned;
       const techAvatars = techs.length > 0
          ? techs.map(techAvatarHtml).join('')
          : '<span class="kanban-avatar kanban-avatar-empty" title="' + escapeHtml(lang.unassigned) + '"><i class="ti ti-user-off"></i></span>';

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
          metaItems.push(`<div class="card-meta-item kanban-tech-avatars" title="${escapeHtml(techNames)}">${techAvatars}</div>`);
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
          metaItems.push(`<div class="card-meta-item ms-auto" title="${escapeHtml(ticket.date_creation)}">
             <i class="ti ti-calendar"></i><span>${escapeHtml(relativeDate(ticket.date_creation))}</span>
          </div>`);
       }

       card.innerHTML = `
          <div class="card-body">
             <div class="kanban-card-top">
                <span class="ticket-id">#${ticket.id}</span>
                <div class="d-flex align-items-center gap-1 flex-shrink-0">
                   ${topActions.join('')}
                   ${buildQuickActionsHtml(ticket)}
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
   // Drag & Drop (move cards between columns)
   // =============================================

   function clearDragHighlights() {
      dragState = null;
      document.querySelectorAll('.kanban-drop-zone-active').forEach(z => z.classList.remove('kanban-drop-zone-active'));
   }

   function onDragStart(e) {
      const card = e.target.closest('.kanban-card');
      if (!card || !enableDragDrop) return;
      const ticketId = card.getAttribute('data-ticket-id');
      const col = card.closest('.kanban-column');
      if (!ticketId) return;
      dragState = { ticketId: ticketId, fromStatus: col ? col.getAttribute('data-status-id') : null };
      e.dataTransfer.setData('text/plain', ticketId);
      e.dataTransfer.effectAllowed = 'move';
   }

   function onDragOver(e) {
      const zone = e.target.closest('.kanban-cards-dropzone');
      if (!zone) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      zone.classList.add('kanban-drop-zone-active');
   }

   function onDragLeave(e) {
      const zone = e.target.closest('.kanban-cards-dropzone');
      if (!zone) return;
      if (!zone.contains(e.relatedTarget)) {
         zone.classList.remove('kanban-drop-zone-active');
      }
   }

   function onDrop(e) {
      const zone = e.target.closest('.kanban-cards-dropzone');
      const inFlight = dragState;
      clearDragHighlights();
      if (!zone || !inFlight) return;
      e.preventDefault();
      const toStatus = String(zone.getAttribute('data-status') || '');
      const fromStatus = String(inFlight.fromStatus || '');
      if (!inFlight.ticketId || !toStatus || fromStatus === toStatus) return;
      openStatusTransitionModal(inFlight.ticketId, fromStatus, toStatus);
   }

   function onDragEnd() {
      clearDragHighlights();
   }

   // =============================================
   // Status transition modal (drag & drop)
   // =============================================

   /**
    * Ensure the transition modal exists once (Bootstrap markup).
    */
   function ensureTransitionModal() {
      let modalEl = document.getElementById('kanbanTransitionModal');
      if (modalEl) return modalEl;

      const wrapper = document.createElement('div');
      wrapper.innerHTML = `
         <div class="modal fade" id="kanbanTransitionModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
               <div class="modal-content">
                  <div class="modal-header">
                     <h5 class="modal-title" id="kanbanTransitionModalTitle"></h5>
                     <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body" id="kanbanTransitionModalBody"></div>
                  <div class="modal-footer">
                     <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">${escapeHtml(lang.cancel || 'Cancel')}</button>
                     <button type="button" class="btn btn-primary" id="kanbanTransitionConfirm">${escapeHtml(lang.confirmMove || 'Move')}</button>
                  </div>
               </div>
            </div>
         </div>
      `;
      modalEl = wrapper.querySelector('#kanbanTransitionModal');
      document.body.appendChild(modalEl);

      modalEl.querySelector('#kanbanTransitionConfirm').addEventListener('click', function () {
         confirmTransition();
      });

      return modalEl;
   }

   /**
    * Build the modal body for a status transition (Pending differs from Solved).
    */
   function buildTransitionBody(toStatus) {
      if (toStatus === '4') {
         const reasonOptions = pendingReasons.map(r => `<option value="${escapeHtml(r.id)}">${escapeHtml(r.name)}</option>`).join('');
         const hasReasons = pendingReasons.length > 0;
         const freqOptions = pendingFrequencyOptions.map(o => `<option value="${o.value}">${escapeHtml(o.label)}</option>`).join('');
         const resOptions = pendingResolutionOptions.map(o => `<option value="${o.value}">${escapeHtml(o.label)}</option>`).join('');
         const reasonLabel = requirePendingReason && hasReasons
            ? `<label for="kanban-move-pendingreason" class="form-label fw-semibold kanban-required-label">${escapeHtml(lang.pendingReason || 'Pending reason')}</label>`
            : `<label for="kanban-move-pendingreason" class="form-label fw-semibold">${escapeHtml(lang.pendingReason || 'Pending reason')}</label>`;
         return `
            <div id="kanban-transition-msg" class="d-none"></div>
            <div class="form-check form-switch mb-2">
               <input class="form-check-input" type="checkbox" role="switch" id="kanban-set-pending" checked>
               <label class="form-check-label fw-semibold" for="kanban-set-pending">${escapeHtml(lang.setStatusPending || 'Set the status to pending')}</label>
            </div>
            ${hasReasons ? `
            <div class="mb-2">
               ${reasonLabel}
               <select class="form-select" id="kanban-move-pendingreason">
                  <option value="">${escapeHtml(lang.noPendingReason || 'Select a reason...')}</option>
                  ${reasonOptions}
               </select>
            </div>
            <div class="row g-2 mb-2">
               <div class="col-sm-6">
                  <label for="kanban-move-followup-freq" class="form-label fw-semibold">${escapeHtml(lang.automaticFollowup || 'Automatic follow-up')}</label>
                  <select class="form-select" id="kanban-move-followup-freq">${freqOptions}</select>
               </div>
               <div class="col-sm-6">
                  <label for="kanban-move-followups-before-res" class="form-label fw-semibold">${escapeHtml(lang.automaticResolution || 'Automatic resolution')}</label>
                  <select class="form-select" id="kanban-move-followups-before-res">${resOptions}</select>
               </div>
            </div>` : ''}
            <div class="mb-2">
               <label for="kanban-move-reason" class="form-label fw-semibold kanban-required-label">${escapeHtml(lang.pendingDescription || 'Description')}</label>
               <textarea class="form-control" id="kanban-move-reason" rows="3" placeholder="${escapeHtml(lang.pendingReasonPlaceholder || 'Describe why the ticket is pending...')}"></textarea>
            </div>
         `;
      }

      if (toStatus === '5') {
         const hasModels = solutionTemplates.length > 0;
         const hasTypes = solutionTypes.length > 0;
         const modelLabel = requireSolutionModel && hasModels
            ? `<label for="kanban-move-template" class="form-label fw-semibold kanban-required-label">${escapeHtml(lang.solutionModel || 'Solution model')}</label>`
            : `<label for="kanban-move-template" class="form-label fw-semibold">${escapeHtml(lang.solutionModel || 'Solution model')}</label>`;
         const typeLabel = requireSolutionType && hasTypes
            ? `<label for="kanban-move-type" class="form-label fw-semibold kanban-required-label">${escapeHtml(lang.solutionType || 'Solution type')}</label>`
            : `<label for="kanban-move-type" class="form-label fw-semibold">${escapeHtml(lang.solutionType || 'Solution type')}</label>`;
         const modelRow = hasModels ? `
            <div class="mb-2">
               ${modelLabel}
               <select class="form-select" id="kanban-move-template">
                  <option value="">${escapeHtml(lang.noSolutionModel || 'Select a model...')}</option>
                  ${solutionTemplates.map(t => `<option value="${escapeHtml(t.id)}">${escapeHtml(t.name)}</option>`).join('')}
               </select>
            </div>` : '';
         const typeRow = hasTypes ? `
            <div class="mb-2">
               ${typeLabel}
               <select class="form-select" id="kanban-move-type">
                  <option value="">${escapeHtml(lang.noSolutionType || 'No type')}</option>
                  ${solutionTypes.map(t => `<option value="${escapeHtml(t.id)}">${escapeHtml(t.name)}</option>`).join('')}
               </select>
            </div>` : '';
         return `
            <div id="kanban-transition-msg" class="d-none"></div>
            <div class="mb-2">
               <label for="kanban-move-solution" class="form-label fw-semibold kanban-required-label">${escapeHtml(lang.solutionDescription || 'Solution')}</label>
               <textarea class="form-control" id="kanban-move-solution" rows="4" placeholder="${escapeHtml(lang.solutionDescription || 'Solution')}..."></textarea>
            </div>
            ${modelRow}
            ${typeRow}
         `;
      }

      return '';
   }

   /**
    * Open the transition modal when the destination status requires extra data.
    * Other statuses transition immediately.
    */
   function openStatusTransitionModal(ticketId, fromStatus, toStatus) {
      if (!toStatus || toStatus === fromStatus) return;

      // Directly transition unless the destination needs the supporting screen.
      if (toStatus !== '4' && toStatus !== '5') {
         performStatusChange(ticketId, toStatus, null);
         return;
      }

      const modalEl = ensureTransitionModal();
      const titleEl = document.getElementById('kanbanTransitionModalTitle');
      const bodyEl = document.getElementById('kanbanTransitionModalBody');
      const statusName = (window.KANBAN_STATUSES || {})[toStatus]?.name || '';
      if (titleEl) titleEl.textContent = (lang.moveTo || 'Move to') + ' ' + (statusName || toStatus) + '  #' + ticketId;
      if (bodyEl) bodyEl.innerHTML = buildTransitionBody(toStatus);

      transitionAction = { ticketId: String(ticketId), toStatus: String(toStatus) };

      // Selecting a model pre-fills the solution description.
      const modelSel = document.getElementById('kanban-move-template');
      if (modelSel) {
         modelSel.addEventListener('change', function () {
            modelSel.classList.remove('is-invalid');
            clearRequiredAlert();
            const t = solutionTemplates.find(x => String(x.id) === String(modelSel.value));
            const sol = document.getElementById('kanban-move-solution');
            if (t && sol) sol.value = t.content || '';
            if (t && t.solutiontypes_id) {
               const typeSel = document.getElementById('kanban-move-type');
               if (typeSel) typeSel.value = String(t.solutiontypes_id);
            }
         });
      }

      // Selecting a pending reason fills the automatic follow-up controls,
      // exactly like the native pendency widget does.
      const prSel = document.getElementById('kanban-move-pendingreason');
      if (prSel) {
         prSel.addEventListener('change', function () {
            prSel.classList.remove('is-invalid');
            clearRequiredAlert();
            const pr = pendingReasons.find(x => String(x.id) === String(prSel.value));
            const f = document.getElementById('kanban-move-followup-freq');
            const r = document.getElementById('kanban-move-followups-before-res');
            const fv = pr ? pr.followup_frequency : 0;
            const rv = pr ? pr.followups_before_resolution : 0;
            if (f) f.value = String(fv);
            if (r) r.value = String(rv);
         });
      }

      // Typing in the description/solution clears the required-field alert.
      const reasonTa = document.getElementById('kanban-move-reason');
      if (reasonTa) {
         reasonTa.addEventListener('input', function () {
            reasonTa.classList.remove('is-invalid');
            clearRequiredAlert();
         });
      }
      const solTa = document.getElementById('kanban-move-solution');
      if (solTa) {
         solTa.addEventListener('input', function () {
            solTa.classList.remove('is-invalid');
            clearRequiredAlert();
         });
      }

      const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
      modal.show();
   }

   function confirmTransition() {
      const action = transitionAction;
      if (!action) return;
      const bodyEl = document.getElementById('kanbanTransitionModalBody');
      if (!bodyEl) return;

      const extra = {};
      const setPending = document.getElementById('kanban-set-pending');
      if (setPending) extra.pending = setPending.checked ? '1' : '0';
      const pReasonSel = document.getElementById('kanban-move-pendingreason');
      const pFreqSel = document.getElementById('kanban-move-followup-freq');
      const pResSel = document.getElementById('kanban-move-followups-before-res');
      if (pReasonSel) extra.pendingreasons_id = pReasonSel.value || '0';
      if (pFreqSel) extra.followup_frequency = pFreqSel.value || '0';
      if (pResSel) extra.followups_before_resolution = pResSel.value || '0';

      const reason = document.getElementById('kanban-move-reason');
      if (reason) extra.pending_reason = reason.value;

      const solution = document.getElementById('kanban-move-solution');
      const templateSel = document.getElementById('kanban-move-template');
      const typeSel = document.getElementById('kanban-move-type');
      if (solution) extra.solution = solution.value;
      if (templateSel && templateSel.value) extra.solution_template_id = templateSel.value;
      if (typeSel && typeSel.value) extra.solution_type_id = typeSel.value;

      // The description is ALWAYS required (no configuration toggle).
      if (action.toStatus === '4' && reason && reason.value.trim() === '') {
         rejectRequiredField(reason, lang.requiredPendingDescription || 'A description is required to move to Pending.');
         return;
      }
      if (action.toStatus === '5' && solution && solution.value.trim() === '') {
         rejectRequiredField(solution, lang.requiredSolutionDescription || 'A solution description is required to move to Solved.');
         return;
      }

      // Enforced required fields (mirrors the plugin configuration).
      if (action.toStatus === '4' && requirePendingReason
         && extra.pending !== '0'
         && (!pReasonSel || !pReasonSel.value || pReasonSel.value === '0')) {
         rejectRequiredField(pReasonSel, lang.requirePendingReason || 'Select a pending reason before moving the card.');
         return;
      }
      if (action.toStatus === '5' && requireSolutionModel
         && (!templateSel || !templateSel.value || templateSel.value === '0')) {
         rejectRequiredField(templateSel, lang.requireSolutionModel || 'Select a solution model before moving the card.');
         return;
      }
      if (action.toStatus === '5' && requireSolutionType
         && (!typeSel || !typeSel.value || typeSel.value === '0')) {
         rejectRequiredField(typeSel, lang.requireSolutionType || 'Select a solution type before moving the card.');
         return;
      }

      transitionAction = null;
      const modalEl = document.getElementById('kanbanTransitionModal');
      if (modalEl) {
         const modal = bootstrap.Modal.getInstance(modalEl);
         if (modal) modal.hide();
      }

      performStatusChange(action.ticketId, action.toStatus, extra);
   }

   /**
    * Show an inline required-field alert in the transition modal, highlight
    * the offending field and scroll it into view.
    */
   function rejectRequiredField(fieldEl, message) {
      if (fieldEl) {
         fieldEl.classList.add('is-invalid');
         fieldEl.focus();
      }
      const msg = document.getElementById('kanban-transition-msg');
      if (msg) {
         msg.className = 'alert alert-danger py-2 px-3 d-flex align-items-center gap-2';
         msg.innerHTML = `<i class="ti ti-alert-triangle"></i><span>${escapeHtml(message)}</span>`;
      }
      showToast(message, 'warning');
   }

   function clearRequiredAlert() {
      const msg = document.getElementById('kanban-transition-msg');
      if (msg) msg.className = 'd-none';
      const bodyEl = document.getElementById('kanbanTransitionModalBody');
      if (bodyEl) bodyEl.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
   }

   /**
    * POST a status change to the Kanban API and reload the board.
    */
   function performStatusChange(ticketId, toStatus, extra) {
      const body = new URLSearchParams();
      body.append('action', 'update_ticket_status');
      body.append('ticket_id', ticketId);
      body.append('status', toStatus);
      if (extra) {
         if (extra.pending) body.append('pending', extra.pending);
         if (extra.pendingreasons_id) body.append('pendingreasons_id', extra.pendingreasons_id);
         if (extra.followup_frequency) body.append('followup_frequency', extra.followup_frequency);
         if (extra.followups_before_resolution) body.append('followups_before_resolution', extra.followups_before_resolution);
         if (extra.pending_reason) body.append('pending_reason', extra.pending_reason);
         if (extra.solution) body.append('solution', extra.solution);
         if (extra.solution_type_id) body.append('solution_type_id', extra.solution_type_id);
         if (extra.solution_template_id) body.append('solution_template_id', extra.solution_template_id);
      }
      if (getCsrfToken()) body.append('_glpi_csrf_token', getCsrfToken());

      fetch(apiUrl, { method: 'POST', body: body, headers: { 'Content-Type': 'application/x-www-form-urlencoded' } })
         .then(r => r.json())
         .then(res => {
            if (res && res.csrf_token) {
               _csrfToken = res.csrf_token;
            }
            const result = res && res.result ? res.result : res;
            if (result && result.success) {
               showToast((lang.statusUpdated || 'Status updated') + ' #' + ticketId, 'success');
               if (result.status_unchanged) showToast(lang.statusUnchanged || 'Status unchanged; only the follow-up was recorded', 'info');
               if (result.followup_skipped) showToast(lang.followupSkipped || 'Pending reason not recorded.', 'warning');
               if (result.solution_skipped) showToast(lang.solutionSkipped || 'Solution not recorded.', 'warning');
               closeCardMenus();
               loadTickets();
            } else {
               showToast((result && result.error) || 'Error', 'danger');
            }
         })
         .catch(() => showToast('Error updating status', 'danger'));
   }

   // =============================================
   // Event Listeners with Debounce
   // =============================================

   let filterDebounceTimer = null;

   function debouncedLoadTickets() {
      if (filterDebounceTimer) clearTimeout(filterDebounceTimer);
      filterDebounceTimer = setTimeout(loadTickets, 300);
   }

    if (getFilterForm()) {
       // Secondary selects: refresh filters and keep the active-count badge in sync
       getFilterForm().querySelectorAll('select').forEach(select => {
         select.addEventListener('change', function () {
            updateAdvancedFilterCount();
            debouncedLoadTickets();
         });
      });
   }

   // Omnichannel search field (live, debounced)
   const searchInput = document.getElementById('filter-search');
   if (searchInput) {
      searchInput.addEventListener('input', debouncedLoadTickets);
   }

   // "Assigned to me" quick filter
   const assignedMeBtn = document.getElementById('kanban-assigned-me-btn');
   if (assignedMeBtn) {
      assignedMeBtn.addEventListener('click', function () {
         setTechnicianFilter(currentUser.id, currentUser.name || ('#' + currentUser.id));
      });
   }

   // "Clear" quick button: reset all filters and reload
   const clearBtn = document.getElementById('kanban-clear-filters-btn');
   if (clearBtn) {
      clearBtn.addEventListener('click', function () {
          if (getFilterForm()) getFilterForm().reset();
         if (searchInput) searchInput.value = '';
         updateAdvancedFilterCount();
         loadTickets();
      });
   }

   // Advanced filters collapse: keep the toggle aria state in sync
   const advPanel = document.getElementById('kanban-advanced-filters');
   if (advPanel) {
      advPanel.addEventListener('hidden.bs.collapse', function () {
         const btn = document.getElementById('kanban-adv-filters-btn');
         if (btn) btn.setAttribute('aria-expanded', 'false');
      });
      advPanel.addEventListener('shown.bs.collapse', function () {
         const btn = document.getElementById('kanban-adv-filters-btn');
         if (btn) btn.setAttribute('aria-expanded', 'true');
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

    // Auto-refresh
    let autoRefreshTimer = null;
    const autoRefreshMenu = document.getElementById('kanban-autorefresh-menu');
    if (autoRefreshMenu) {
       // Restore saved preference
       const savedInterval = parseInt(localStorage.getItem('kanban_autorefresh') || '0', 10);
       if (savedInterval > 0) {
          startAutoRefresh(savedInterval);
          updateAutoRefreshUI(savedInterval);
       }

       autoRefreshMenu.addEventListener('click', function (e) {
          e.preventDefault();
          const link = e.target.closest('[data-interval]');
          if (!link) return;
          const interval = parseInt(link.dataset.interval, 10);
          localStorage.setItem('kanban_autorefresh', interval);
          updateAutoRefreshUI(interval);
          if (interval > 0) {
             startAutoRefresh(interval);
          } else {
             stopAutoRefresh();
          }
       });
    }

    function startAutoRefresh(seconds) {
       stopAutoRefresh();
       autoRefreshTimer = setInterval(function () {
          loadTickets();
       }, seconds * 1000);
    }

    function stopAutoRefresh() {
       if (autoRefreshTimer) {
          clearInterval(autoRefreshTimer);
          autoRefreshTimer = null;
       }
    }

    function updateAutoRefreshUI(activeInterval) {
       if (!autoRefreshMenu) return;
       autoRefreshMenu.querySelectorAll('.dropdown-item').forEach(function (item) {
          item.classList.remove('active');
          if (parseInt(item.dataset.interval, 10) === activeInterval) {
             item.classList.add('active');
          }
       });
       const btn = document.getElementById('kanban-autorefresh-btn');
       if (btn) {
          const icon = btn.querySelector('i');
          if (activeInterval > 0) {
             if (icon) icon.classList.add('text-success');
          } else {
             if (icon) icon.classList.remove('text-success');
          }
       }
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
          ? ticket.assigned_techs.map(t => (t && t.name) || t).join(', ')
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

       // Content is already sanitized server-side via GLPI RichText::getSafeHtml().
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

/**
 * Apply the kanban-theme-dark class when GLPI's native theme is dark.
 * GLPI 11 sets <html data-glpi-theme-dark="1">, GLPI 10 exposes the --is-dark
 * CSS variable on :root. Idempotent with the inline script in the page.
 */
function applyNativeTheme() {
   const wrapper = document.querySelector('.kanban-page-wrapper');
   if (!wrapper) return;

   let dark = document.documentElement.getAttribute('data-glpi-theme-dark');
   if (dark !== '1' && dark !== '0') {
      dark = getComputedStyle(document.documentElement).getPropertyValue('--is-dark').trim() === 'true' ? '1' : '0';
   }
   wrapper.classList.toggle('kanban-theme-dark', dark === '1');
}

document.addEventListener('DOMContentLoaded', function () {
   applyNativeTheme();
   renderBoardColumns();
   populateColumnsMenu();
   populateCardFieldsMenu();
   applyColumnVisibility();
   bindGroupTechnicianFilter();
   updateAdvancedFilterCount();
   loadTickets();

   // Columns visibility menu (may live outside the board container).
   // The checkboxes handle their own toggling via the "change" event.
   document.addEventListener('click', function (e) {
      const showAll = e.target.closest('.kanban-columns-show-all');
      if (showAll) {
         e.preventDefault();
         hiddenColumns.clear();
         saveHiddenColumns();
         applyColumnVisibility();
      }

      // Quick actions menu on cards
      const card = e.target.closest('.kanban-card');
      const menuBtn = e.target.closest('.kanban-card-menu-btn');
      if (menuBtn) {
         e.preventDefault();
         toggleCardMenu(menuBtn);
         return;
      }

      if (card) {
         const ticketId = card.getAttribute('data-ticket-id');
         if (e.target.closest('.kanban-act-assign')) {
            e.preventDefault();
            closeCardMenus();
            assignTicketToMe(ticketId);
            return;
         }
         if (e.target.closest('.kanban-act-followups')) {
            e.preventDefault();
            closeCardMenus();
            showFollowups(ticketId);
            return;
         }
         const prioOpt = e.target.closest('.kanban-prio-opt');
         if (prioOpt) {
            e.preventDefault();
            setTicketPriority(ticketId, prioOpt.getAttribute('data-priority'));
            return;
         }
         // Click inside a quick-actions container keeps the menu open
         if (e.target.closest('.kanban-quick-actions')) {
            return;
         }
      }

      // Any other click closes the open card menus
      closeCardMenus();
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

    // Drag & drop (delegated so re-rendered cards keep working)
    if (enableDragDrop) {
       document.addEventListener('dragstart', onDragStart);
       document.addEventListener('dragover', onDragOver);
       document.addEventListener('dragleave', onDragLeave);
       document.addEventListener('drop', onDrop);
       document.addEventListener('dragend', onDragEnd);
    }

    // Start countdown (will self-regulate based on visible timers)
    startTimerIfNeeded();
});

})();






