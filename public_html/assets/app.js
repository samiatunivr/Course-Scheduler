// Shared helpers + dashboard charts (ES2020+, no build step required)

async function api(method, url, body = null) {
  const res = await fetch(url, {
    method,
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
    body: body !== null ? JSON.stringify(body) : null,
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || `Request failed (${res.status})`);
  return data;
}

// Dashboard charts (only render when the dashboard provided the data)
document.addEventListener('DOMContentLoaded', () => {
  if (!window.DASH || typeof Chart === 'undefined') return;

  const roomCanvas = document.getElementById('roomChart');
  if (roomCanvas && DASH.rooms.length) {
    new Chart(roomCanvas, {
      type: 'bar',
      data: {
        labels: DASH.rooms.map(r => r.code),
        datasets: [{
          label: 'Utilization %',
          data: DASH.rooms.map(r => r.utilization_pct),
          backgroundColor: DASH.rooms.map(r =>
            r.utilization_pct > 80 ? '#b02a37' : r.utilization_pct < 20 ? '#f0ad4e' : '#1f4e79'),
        }],
      },
      options: { scales: { y: { beginAtZero: true, max: 100 } }, plugins: { legend: { display: false } } },
    });
  }

  const deptCanvas = document.getElementById('deptChart');
  if (deptCanvas && DASH.depts.length) {
    new Chart(deptCanvas, {
      type: 'bar',
      data: {
        labels: DASH.depts.map(d => d.department),
        datasets: [
          { label: 'Avg credit load', data: DASH.depts.map(d => d.avg_credits), backgroundColor: '#1f4e79' },
          { label: 'Overloaded', data: DASH.depts.map(d => d.overloaded), backgroundColor: '#b02a37' },
          { label: 'Underloaded', data: DASH.depts.map(d => d.underloaded), backgroundColor: '#f0ad4e' },
        ],
      },
      options: { scales: { y: { beginAtZero: true } } },
    });
  }
});
