// Drag-and-drop weekly scheduling board + section list.
// Sections are fetched from the API, rendered on the grid, and meetings
// can be dragged to another day/hour slot (PUT /api/v1/meetings/{id}).

(() => {
  let sections = [];
  let conflictSectionIds = new Set();

  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

  async function load() {
    sections = await api('GET', `/api/v1/terms/${APP.termId}/sections`);
    const conflicts = await api('GET', `/api/v1/terms/${APP.termId}/conflicts`);
    conflictSectionIds = new Set(conflicts.flatMap(c =>
      [c.section_id, c.conflicting_section_id].filter(Boolean).map(Number)));
    render();
  }

  function deptFilterId() {
    const v = document.getElementById('deptFilter').value;
    return v ? parseInt(v) : null;
  }

  function visibleRows() {
    const dept = deptFilterId();
    return sections.filter(s => dept === null || parseInt(s.department_id) === dept);
  }

  function render() {
    renderGrid();
    renderList();
  }

  function renderGrid() {
    document.querySelectorAll('#scheduleGrid td.slot').forEach(td => td.innerHTML = '');
    for (const row of visibleRows()) {
      if (!row.meeting_id || !row.day) continue;
      const hour = parseInt(row.start_time.slice(0, 2));
      const slot = document.querySelector(`#scheduleGrid td.slot[data-day="${row.day}"][data-hour="${hour}"]`);
      if (!slot) continue;
      const card = document.createElement('div');
      card.className = 'class-card' + (conflictSectionIds.has(Number(row.id)) ? ' conflicted' : '');
      card.draggable = true;
      card.dataset.meetingId = row.meeting_id;
      card.innerHTML = `<strong>${esc(row.course)}-${esc(row.section_no)}</strong>
        <div class="meta">${esc(row.start_time?.slice(0,5))}–${esc(row.end_time?.slice(0,5))}
        · ${esc(row.room || 'TBA')}<br>${esc(row.instructor || 'No instructor')}</div>`;
      card.title = `${row.title} (${row.kind || 'lecture'}) — drag to reschedule`;
      slot.appendChild(card);
    }
  }

  function renderList() {
    const tbody = document.querySelector('#sectionTable tbody');
    // group meetings per section
    const bySection = new Map();
    for (const row of visibleRows()) {
      if (!bySection.has(row.id)) bySection.set(row.id, { ...row, meetings: [] });
      if (row.day) bySection.get(row.id).meetings.push(row);
    }
    tbody.innerHTML = [...bySection.values()].map(s => `
      <tr class="${conflictSectionIds.has(Number(s.id)) ? 'table-danger' : ''}">
        <td class="fw-semibold">${esc(s.course)}</td>
        <td>${esc(s.section_no)}</td>
        <td>${esc(s.title)}</td>
        <td>${esc(s.instructor || '—')}</td>
        <td class="small">${s.meetings.map(m => `${esc(m.day)} ${esc(m.start_time?.slice(0,5))}–${esc(m.end_time?.slice(0,5))}`).join('<br>') || '—'}</td>
        <td>${esc(s.meetings[0]?.room || 'TBA')}</td>
        <td>${esc(s.enrolled)}/${esc(s.capacity)}</td>
        <td><span class="badge text-bg-light border">${esc(s.status)}</span></td>
        <td><button class="btn btn-sm btn-outline-danger py-0" data-cancel="${esc(s.id)}"><i class="bi bi-x-lg"></i></button></td>
      </tr>`).join('');
  }

  // ---- Drag & drop ----
  document.addEventListener('dragstart', e => {
    const card = e.target.closest('.class-card');
    if (!card) return;
    card.classList.add('dragging');
    e.dataTransfer.setData('text/plain', card.dataset.meetingId);
  });
  document.addEventListener('dragend', e => {
    e.target.closest?.('.class-card')?.classList.remove('dragging');
  });
  document.addEventListener('dragover', e => {
    const slot = e.target.closest('td.slot');
    if (slot) { e.preventDefault(); slot.classList.add('drag-over'); }
  });
  document.addEventListener('dragleave', e => e.target.closest?.('td.slot')?.classList.remove('drag-over'));
  document.addEventListener('drop', async e => {
    const slot = e.target.closest('td.slot');
    if (!slot) return;
    e.preventDefault();
    slot.classList.remove('drag-over');
    const meetingId = e.dataTransfer.getData('text/plain');
    const row = sections.find(s => String(s.meeting_id) === meetingId);
    if (!row) return;
    // preserve duration, snap start to the slot hour
    const duration = (toMin(row.end_time) - toMin(row.start_time)) || 50;
    const start = parseInt(slot.dataset.hour) * 60;
    try {
      const r = await api('PUT', `/api/v1/meetings/${meetingId}`, {
        day: slot.dataset.day,
        start_time: toTime(start),
        end_time: toTime(start + duration),
      });
      flashBanner(`Meeting moved. ${r.open_conflicts} open conflict(s) after re-validation.`, r.open_conflicts ? 'warning' : 'success');
      await load();
    } catch (err) { alert(err.message); }
  });

  const toMin = t => t ? parseInt(t.slice(0, 2)) * 60 + parseInt(t.slice(3, 5)) : 0;
  const toTime = m => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}:00`;

  function flashBanner(text, kind) {
    const el = document.getElementById('genResult');
    el.className = `alert alert-${kind} small`;
    el.textContent = text;
  }

  // ---- Toolbar actions ----
  document.getElementById('deptFilter').addEventListener('change', render);

  document.getElementById('btnDetect').addEventListener('click', async () => {
    const r = await api('POST', `/api/v1/terms/${APP.termId}/detect-conflicts`);
    flashBanner(`Validation complete: ${r.count} conflict(s) found. Reloading…`, r.count ? 'warning' : 'success');
    setTimeout(() => location.reload(), 800);
  });

  document.getElementById('btnGenerate').addEventListener('click', async () => {
    if (!confirm('Generate a draft schedule with the AI engine? Existing draft sections will be replaced.')) return;
    flashBanner('Generating schedule — applying faculty qualifications, availability, workload caps and room constraints…', 'info');
    const r = await api('POST', `/api/v1/terms/${APP.termId}/generate`, {
      replace: true,
      department_id: deptFilterId(),
    });
    flashBanner(`Generated ${r.sections_created} section(s); ${r.unassigned.length} could not be placed` +
      (r.unassigned.length ? ': ' + r.unassigned.map(u => u.course).join(', ') : '.'), r.unassigned.length ? 'warning' : 'success');
    await load();
  });

  document.querySelector('#sectionTable').addEventListener('click', async e => {
    const btn = e.target.closest('[data-cancel]');
    if (!btn) return;
    if (!confirm('Cancel this section?')) return;
    await api('DELETE', `/api/v1/sections/${btn.dataset.cancel}`);
    await load();
  });

  // ---- Add-section modal ----
  (async () => {
    const courses = await api('GET', '/api/v1/courses');
    document.getElementById('newCourse').innerHTML =
      courses.map(c => `<option value="${c.id}" data-cap="${esc(c.default_capacity)}">${esc(c.code)} — ${esc(c.title)}</option>`).join('');
  })();

  document.getElementById('btnCreateSection').addEventListener('click', async () => {
    const pattern = JSON.parse(document.getElementById('newPattern').value);
    const roomId = document.getElementById('newRoom').value;
    try {
      await api('POST', '/api/v1/sections', {
        course_id: parseInt(document.getElementById('newCourse').value),
        term_id: APP.termId,
        section_no: document.getElementById('newSectionNo').value,
        capacity: parseInt(document.getElementById('newCapacity').value) || 30,
        meetings: pattern.days.map(day => ({
          day,
          start_time: pattern.start,
          end_time: pattern.end,
          room_id: roomId ? parseInt(roomId) : null,
        })),
      });
      bootstrap.Modal.getInstance(document.getElementById('addSectionModal')).hide();
      await load();
    } catch (err) { alert(err.message); }
  });

  load();
})();
