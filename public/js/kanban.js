/**
 * GLPI Kanban Plugin - JS Controller
 */

document.addEventListener('DOMContentLoaded', function() {
   const filterForm = document.getElementById('kanban-filter-form');
   const boardContainer = document.getElementById('kanban-board');
   const csrfToken = document.querySelector('input[name="_glpi_csrf_token"]')?.value;

    // Render the Kanban Board structure initially
    const statuses = window.KANBAN_STATUSES || {
       1: { name: 'New', color: 'primary' },
       2: { name: 'Assigned', color: 'info' },
       3: { name: 'Planned', color: 'warning' },
       4: { name: 'Pending', color: 'secondary' },
       5: { name: 'Solved', color: 'success' },
       6: { name: 'Closed', color: 'dark' }
    };

   // Initialize board columns if container exists and is empty
   if (boardContainer && boardContainer.children.length === 0) {
      let boardHtml = '';
      for (const [id, status] of Object.entries(statuses)) {
         boardHtml += `
            <div class="kanban-column flex-shrink-0 rounded-3 p-3 bg-light border" data-status-id="${id}" style="width: 300px; min-height: 600px;">
               <div class="kanban-column-header d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                  <div class="d-flex align-items-center gap-2">
                     <span class="status-indicator rounded-circle bg-${status.color}" style="width: 10px; height: 10px; display: inline-block;"></span>
                     <h5 class="card-title m-0 fw-semibold">${status.name}</h5>
                  </div>
                  <span class="badge bg-secondary text-white rounded-pill px-2 py-1 fs-7 card-count" id="count-${id}">0</span>
               </div>
               <div class="kanban-cards-dropzone d-flex flex-column gap-3" id="status-column-${id}" data-status="${id}" style="min-height: 500px;">
                  <!-- Dynamically filled -->
               </div>
            </div>
         `;
      }
      boardContainer.innerHTML = boardHtml;
   }

   // Fetch and render tickets
   function loadTickets() {
      const formData = new FormData(filterForm);
      const params = new URLSearchParams();
      params.append('action', 'get_tickets');
      // Adicionado cache-buster para forçar a atualização
      params.append('_t', Date.now());
      
      for (const [key, value] of formData.entries()) {
         if (value) {
            params.append(key, value);
         }
      }

      fetch(`kanban.php?${params.toString()}`)
         .then(response => response.json())
         .then(data => {
            // Clear all dropzones first
            document.querySelectorAll('.kanban-cards-dropzone').forEach(zone => {
               zone.innerHTML = '';
            });

            // Populate columns
            for (const [statusId, tickets] of Object.entries(data)) {
               const zone = document.getElementById(`status-column-${statusId}`);
               const countBadge = document.getElementById(`count-${statusId}`);
               
               if (countBadge) {
                  countBadge.textContent = tickets.length;
               }

               if (zone) {
                  tickets.forEach(ticket => {
                     zone.appendChild(createCardElement(ticket));
                  });
               }
            }
            initDragAndDrop();
            updateCountdowns();
         })
         .catch(err => console.error('Error fetching tickets:', err));
   }

   // Create HTML card element for a Ticket
   function createCardElement(ticket) {
      const card = document.createElement('div');
      card.className = 'kanban-card card shadow-sm p-3 bg-white rounded border-start border-4';
      card.setAttribute('draggable', 'true');
      card.setAttribute('data-ticket-id', ticket.id);

      // Apply border styling based on priority
      const priorityColors = {
         1: '#ced4da', // Very Low
         2: '#adc5e3', // Low
         3: '#0d6efd', // Normal
         4: '#ffc107', // High
         5: '#fd7e14', // Very High
         6: '#dc3545'  // Major
      };
      card.style.borderLeftColor = priorityColors[ticket.priority] || '#0d6efd';

      // SLA Progress Bar HTML
      let slaBarHtml = '';
      if (ticket.sla_progress && ticket.sla_progress.status !== 'no_sla' && ticket.time_to_resolve) {
         slaBarHtml = `
            <div class="sla-progress mb-2">
               <div class="d-flex justify-content-between align-items-center mb-1 fs-8">
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
      }

      // Technicians text
      const techNames = ticket.assigned_techs && ticket.assigned_techs.length > 0
         ? ticket.assigned_techs.join(', ')
         : 'Unassigned';

      // Construct the ticket URL using the GLPI root (injected via PHP)
      const glpiRoot = window.KANBAN_GLPI_ROOT || '';
      const ticketUrl = `${glpiRoot}/front/ticket.form.php?id=${ticket.id}`;

      card.innerHTML = `
         <div class="card-body p-0">
            <h6 class="card-title fw-bold text-dark mb-1">
               <a href="${ticketUrl}" target="_blank" class="text-decoration-none stretched-link-title">
                  #${ticket.id} - ${escapeHtml(ticket.title)}
               </a>
            </h6>
            <div class="mb-2">
               <span class="badge bg-light text-dark border fs-8">${escapeHtml(ticket.category || 'No Category')}</span>
            </div>
            ${slaBarHtml}
            <div class="card-meta d-flex flex-column gap-1 border-top pt-2 mt-2 fs-8 text-secondary">
               <div><i class="ti ti-calendar me-1"></i> ${ticket.date_creation}</div>
               <div class="text-truncate" title="${escapeHtml(techNames)}"><i class="ti ti-user me-1"></i> ${escapeHtml(techNames)}</div>
            </div>
            <div class="d-flex justify-content-end mt-2">
               <a href="${ticketUrl}" target="_blank"
                  class="btn btn-xs btn-outline-primary d-flex align-items-center gap-1"
                  style="font-size:0.7rem; padding: 2px 8px;"
                  onclick="event.stopPropagation()">
                  <i class="ti ti-external-link" style="font-size:0.75rem;"></i>
                  Open in GLPI
               </a>
            </div>
         </div>
      `;

      return card;
   }

   function escapeHtml(str) {
      if (!str) return '';
      return str.replace(/[&<>'"]/g, 
         tag => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[tag] || tag)
      );
   }

   // Drag and Drop implementation
   let draggedCard = null;

   function initDragAndDrop() {
      const cards = document.querySelectorAll('.kanban-card');
      const dropzones = document.querySelectorAll('.kanban-cards-dropzone');

      cards.forEach(card => {
         card.addEventListener('dragstart', function(e) {
            draggedCard = this;
            setTimeout(() => this.classList.add('dragging'), 0);
         });

         card.addEventListener('dragend', function() {
            setTimeout(() => this.classList.remove('dragging'), 0);
            draggedCard = null;
         });
      });

      dropzones.forEach(zone => {
         zone.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('dragover');
         });

         zone.addEventListener('dragleave', function() {
            this.classList.remove('dragover');
         });

         zone.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');

            if (draggedCard) {
               const ticketId = draggedCard.getAttribute('data-ticket-id');
               const targetStatus = this.getAttribute('data-status');

               // Optimistic UI Update
               this.appendChild(draggedCard);
               updateColumnCounts();

               // Backend API call to update status
               const body = new URLSearchParams();
               body.append('action', 'update_ticket_status');
               body.append('ticket_id', ticketId);
               body.append('status', targetStatus);
               body.append('_glpi_csrf_token', csrfToken);

               fetch('kanban.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                  body: body.toString()
               })
               .then(response => response.json())
               .then(res => {
                  if (res.success) {
                     showToast('Status updated successfully!', 'success');
                  } else {
                     // Revert or show alert on failure
                     loadTickets();
                     showToast('Failed to update status. Permission denied.', 'danger');
                  }
               })
               .catch(err => {
                  console.error('Error updating status:', err);
                  loadTickets();
                  showToast('Error updating status.', 'danger');
               });
            }
         });
      });
   }

   function updateColumnCounts() {
      document.querySelectorAll('.kanban-column').forEach(col => {
         const colId = col.getAttribute('data-status-id');
         const count = col.querySelectorAll('.kanban-card').length;
         const badge = document.getElementById(`count-${colId}`);
         if (badge) {
            badge.textContent = count;
         }
      });
   }

   /**
    * Show a temporary toast notification at the top-right corner.
    * @param {string} message - Text to display
    * @param {string} type    - Bootstrap color ('success', 'danger', 'warning', 'info')
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
      toast.className = `alert alert-${type} alert-dismissible shadow d-flex align-items-center gap-2 py-2 px-3`;
      toast.style.cssText = 'min-width:260px;max-width:350px;animation:fadeIn 0.2s ease;';
      toast.innerHTML = `
         <i class="ti ti-${type === 'success' ? 'circle-check' : 'alert-circle'}"></i>
         <span>${message}</span>
         <button type="button" class="btn-close ms-auto" style="font-size:0.7rem;" onclick="this.closest('.alert').remove()"></button>
      `;
      container.appendChild(toast);

      // Auto-dismiss after 3.5 seconds
      setTimeout(() => toast.remove(), 3500);
   }

   // Event Listeners for Filters
   if (filterForm) {
      filterForm.querySelectorAll('select').forEach(select => {
         select.addEventListener('change', loadTickets);
      });
   }

   // Refresh button listener
   const refreshBtn = document.getElementById('kanban-refresh-btn');
   if (refreshBtn) {
      refreshBtn.addEventListener('click', function() {
         const icon = this.querySelector('i');
         if (icon) {
            // Optional visual feedback
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

   // Countdown Logic
   function updateCountdowns() {
      const timers = document.querySelectorAll('.sla-countdown-timer');
      const now = new Date().getTime();

      timers.forEach(timer => {
         const deadlineStr = timer.getAttribute('data-deadline');
         if (!deadlineStr) return;

         // Ensure cross-browser date parsing for YYYY-MM-DD HH:mm:ss
         const deadline = new Date(deadlineStr.replace(' ', 'T')).getTime();
         const distance = deadline - now;

         if (distance < 0) {
            // Tempo expirado (Atrasado)
            // Calculamos a diferença positiva para continuar contando quanto tempo passou após o atraso
            const overdue = Math.abs(distance);
            const oDays = Math.floor(overdue / (1000 * 60 * 60 * 24));
            const oHours = Math.floor((overdue % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const oMinutes = Math.floor((overdue % (1000 * 60 * 60)) / (1000 * 60));
            const oSeconds = Math.floor((overdue % (1000 * 60)) / 1000);

            let timeStr = '-';
            if (oDays > 0) timeStr += `${oDays}d `;
            timeStr += `${oHours.toString().padStart(2, '0')}:${oMinutes.toString().padStart(2, '0')}:${oSeconds.toString().padStart(2, '0')}`;
            
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
         if (days > 0) timeStr += `${days}d `;
         timeStr += `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
         
         timer.textContent = timeStr;

         // Dynamic text color
         timer.classList.remove('text-danger', 'text-warning', 'text-success');
         if (days === 0 && hours < 2) {
             timer.classList.add('text-warning');
         } else {
             timer.classList.add('text-success');
         }
      });
   }

   // Update countdowns every second
   setInterval(updateCountdowns, 1000);

   // Initial load
   loadTickets();
});
