<?php use function App\Core\e; ?>
<h1 class="h3 mb-3">AI Scheduling Assistant — <?= e($term['name']) ?></h1>

<?php if (!$aiConfigured): ?>
<div class="alert alert-warning py-2 small">
  <i class="bi bi-info-circle me-1"></i>No LLM API key configured (<code>AI_API_KEY</code>), so the assistant runs in
  <strong>built-in analytics mode</strong>: it answers from live institutional data using deterministic logic.
  Add an OpenAI-compatible key in <code>.env</code> for full natural-language reasoning.
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm">
      <div class="card-body d-flex flex-column" style="height: 65vh">
        <div id="chatLog" class="flex-grow-1 overflow-auto mb-3 px-1">
          <div class="chat-msg assistant">
            <div class="bubble">
              👋 I'm your AI Scheduling Assistant. I can analyze schedules, workloads, conflicts, rooms and
              enrollment for <strong><?= e($term['name']) ?></strong>. Try one of the quick prompts →
            </div>
          </div>
        </div>
        <form id="chatForm" class="d-flex gap-2">
          <input class="form-control" id="chatInput" placeholder="e.g. Show overloaded faculty" autocomplete="off">
          <button class="btn btn-primary"><i class="bi bi-send"></i></button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold">Quick prompts</div>
      <div class="list-group list-group-flush" id="quickPrompts">
        <?php foreach ([
            'Find all scheduling conflicts',
            'Show overloaded faculty',
            'Which rooms are underutilized?',
            'Predict next semester enrollment',
            'Suggest instructors for CSC420',
            'Generate workload report',
            'Generate the schedule for this term',
        ] as $q): ?>
        <button type="button" class="list-group-item list-group-item-action small"><?= e($q) ?></button>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<script>
const sessionId = crypto.randomUUID();
const log = document.getElementById('chatLog');

function addMsg(role, text) {
  const div = document.createElement('div');
  div.className = 'chat-msg ' + role;
  const bubble = document.createElement('div');
  bubble.className = 'bubble';
  // minimal markdown: bold + line breaks (text content is escaped via textContent first)
  const tmp = document.createElement('div');
  tmp.textContent = text;
  bubble.innerHTML = tmp.innerHTML
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/\n/g, '<br>');
  div.appendChild(bubble);
  log.appendChild(div);
  log.scrollTop = log.scrollHeight;
  return bubble;
}

async function send(message) {
  addMsg('user', message);
  const thinking = addMsg('assistant', '…');
  try {
    const r = await api('POST', '/api/v1/ai/chat', {message, session_id: sessionId, term_id: APP.termId});
    thinking.parentElement.remove();
    addMsg('assistant', r.reply);
  } catch (e) {
    thinking.parentElement.remove();
    addMsg('assistant', '⚠️ ' + e.message);
  }
}

document.getElementById('chatForm').addEventListener('submit', e => {
  e.preventDefault();
  const input = document.getElementById('chatInput');
  if (input.value.trim()) { send(input.value.trim()); input.value = ''; }
});
document.getElementById('quickPrompts').addEventListener('click', e => {
  if (e.target.matches('button')) send(e.target.textContent.trim());
});
</script>
