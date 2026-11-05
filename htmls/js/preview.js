(() => {
  const demoTasks = [
    { id: 12, city: '武汉', status: 'active', stage: 'serp', progress: 35, target_market: '全球', keyword_count: 24, pages_target: 3 },
    { id: 11, city: '宁波', status: 'paused', stage: 'classify', progress: 58, target_market: '欧美', keyword_count: 18, pages_target: 2 },
    { id: 10, city: '深圳', status: 'completed', stage: 'done', progress: 100, target_market: '全球', keyword_count: 24, pages_target: 3 },
  ];

  const STAGE_LABEL = {
    keywords: '关键词', serp: 'Google', classify: 'AI筛选', discover: '页面识别', extract: '联系提取', done: '完成',
  };

  const companies = [
    {
      id: 1,
      company_name: '武汉汉星激光设备有限公司',
      city: '武汉',
      domain: 'hanstarmachine.com',
      phone: '+86 27 8737 6766',
      email: 'vivian@hanstarmachine.com',
      address: '华工科技产业园，东湖高新区，武汉',
    },
    {
      id: 2,
      company_name: '宁波精工外贸机械',
      city: '宁波',
      domain: 'nbprecision.com',
      phone: '+86 138 5710 2201',
      email: 'sales@nbprecision.com',
      address: '',
    },
    {
      id: 3,
      company_name: '深圳联达电子科技',
      city: '深圳',
      domain: 'lianda-tech.com',
      phone: '+86 755 8601 9988',
      email: 'info@lianda-tech.com',
      address: '南山区科技园南区',
    },
  ];

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  function switchTab(name) {
    $$('.screen').forEach((el) => el.classList.toggle('active', el.dataset.screen === name));
    $$('.tabbar button').forEach((btn) => btn.classList.toggle('active', btn.dataset.tab === name));
    const url = new URL(location.href);
    url.searchParams.set('tab', name);
    history.replaceState(null, '', url);
  }

  function renderCompanies(list) {
    const host = $('#companyList');
    $('#companyTotal').textContent = String(list.length);
    if (!list.length) {
      host.innerHTML = '<div class="card"><h3 class="empty-title">暂无匹配企业</h3><p class="muted">换个关键词试试。</p></div>';
      return;
    }
    host.innerHTML = list.map((item) => `
      <div class="card company-card">
        <div class="row-top">
          <div style="flex:1">
            <p class="name">${escapeHtml(item.company_name)}</p>
            <p class="muted">${escapeHtml([item.city, item.domain].filter(Boolean).join(' · '))}</p>
          </div>
          ${item.city ? `<span class="badge muted">${escapeHtml(item.city)}</span>` : ''}
        </div>
        ${item.phone ? `<a class="phone-chip" href="tel:${item.phone.replace(/[^\d+]/g, '')}"><strong>${escapeHtml(item.phone)}</strong><small>点击拨打</small></a>` : ''}
        ${item.email ? `<p class="muted">${escapeHtml(item.email)}</p>` : ''}
        ${item.address ? `<p class="muted">${escapeHtml(item.address)}</p>` : ''}
      </div>
    `).join('');
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (ch) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[ch]));
  }

  function filterCompanies() {
    const q = ($('#quickSearch').value || '').trim().toLowerCase();
    const city = ($('#filterCity').value || '').trim().toLowerCase();
    const company = ($('#filterCompany').value || '').trim().toLowerCase();
    const phone = ($('#filterPhone').value || '').trim().toLowerCase();
    const list = companies.filter((row) => {
      const hay = [row.company_name, row.city, row.domain, row.phone, row.email].join(' ').toLowerCase();
      if (q && !hay.includes(q)) return false;
      if (city && !(row.city || '').toLowerCase().includes(city)) return false;
      if (company && !((row.company_name || '') + (row.domain || '')).toLowerCase().includes(company)) return false;
      if (phone && !(row.phone || '').toLowerCase().includes(phone)) return false;
      return true;
    });
    renderCompanies(list);
  }

  function setAccept(on) {
    $('#acceptTitle').textContent = on ? '接单中' : '未接单';
    $('#acceptHint').textContent = on ? '正在监听拨号队列' : '打开开关开始自动领取';
    const badge = $('#statusBadge');
    badge.textContent = on ? '接单中 · 每 10 秒轮询' : '接单已关闭';
    badge.className = on ? 'badge' : 'badge muted';
  }

  function showJob(show) {
    $('#emptyCard').hidden = show;
    $('#jobCard').hidden = !show;
    $('#currentCount').textContent = show ? '1' : '0';
    if (show) {
      $('#statusBadge').textContent = '拨号盘已打开，请完成通话后标记结果';
      $('#statusBadge').className = 'badge';
      $('#pendingCount').textContent = '2';
    }
  }

  $$('.tabbar button').forEach((btn) => {
    btn.addEventListener('click', () => switchTab(btn.dataset.tab));
  });

  $('#acceptSwitch')?.addEventListener('change', (e) => {
    const on = e.target.checked;
    setAccept(on);
    if (on) showJob(true);
    else {
      showJob(false);
      $('#pendingCount').textContent = '3';
    }
  });

  $('#checkQueueBtn')?.addEventListener('click', () => {
    $('#acceptSwitch').checked = true;
    setAccept(true);
    showJob(true);
  });

  $('#doneBtn')?.addEventListener('click', () => {
    showJob(false);
    $('#statusBadge').textContent = '已完成，继续等待下一单';
    $('#statusBadge').className = 'badge';
    $('#pendingCount').textContent = '2';
  });

  $('#failBtn')?.addEventListener('click', () => {
    showJob(false);
    $('#statusBadge').textContent = '已标记，继续等待下一单';
    $('#statusBadge').className = 'badge warn';
  });

  $('#redialBtn')?.addEventListener('click', () => {
    const phone = ($('#jobPhone').textContent || '').replace(/[^\d+]/g, '');
    location.href = 'tel:' + phone;
  });

  $('#filterToggle')?.addEventListener('click', () => {
    const box = $('#advancedFilters');
    const open = box.classList.toggle('open');
    $('#filterToggle').textContent = open ? '收起筛选' : '高级筛选';
  });

  $('#searchBtn')?.addEventListener('click', filterCompanies);
  $('#quickSearch')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') filterCompanies();
  });

  $('#saveBtn')?.addEventListener('click', () => {
    const probe = $('#probeText');
    const aiUrl = ($('#aiBaseUrl')?.value || '').trim();
    if (aiUrl && !/^https?:\/\//i.test(aiUrl)) {
      probe.hidden = false;
      probe.className = 'probe bad';
      probe.textContent = 'AI URL 必须以 http:// 或 https:// 开头';
      return;
    }
    probe.hidden = false;
    probe.className = 'probe ok';
    probe.textContent = '已保存（预览模式：真实 App 会同步到 /api/settings）';
    if ($('#aiStatus')) {
      $('#aiStatus').textContent = '预览已配置 · ' + (($('#aiModel')?.value || 'AI').trim() || 'AI');
    }
  });

  $('#testBtn')?.addEventListener('click', async () => {
    const probe = $('#probeText');
    const base = ($('#apiBase').value || '').replace(/\/+$/, '');
    probe.hidden = false;
    probe.className = 'probe';
    probe.textContent = '检测中…';
    try {
      const res = await fetch(base + '/api/health', { cache: 'no-store' });
      const data = await res.json();
      if (data && data.ok) {
        probe.className = 'probe ok';
        probe.textContent = `连接正常 · ${data.data?.app || 'GLE'} · PHP ${data.data?.php || '?'}`;
      } else {
        probe.className = 'probe bad';
        probe.textContent = '健康检查未通过';
      }
    } catch (err) {
      probe.className = 'probe bad';
      probe.textContent = '无法连接（预览页跨域或网络限制也属正常）';
    }
  });

  function renderTasks() {
    const host = $('#taskList');
    if (!host) return;
    host.innerHTML = demoTasks.map((task) => `
      <div class="card">
        <div class="accept-row">
          <div style="flex:1">
            <p class="company-name">${escapeHtml(task.city)}</p>
            <p class="muted">#${task.id} · ${STAGE_LABEL[task.stage] || task.stage} · 进度 ${task.progress}%</p>
          </div>
          <span class="badge ${task.status === 'active' ? '' : (task.status === 'paused' ? 'warn' : 'muted')}">${escapeHtml(task.status)}</span>
        </div>
        <div class="chip-row">
          <button class="chip" type="button">编辑</button>
          <button class="chip ${task.status === 'active' ? 'danger' : ''}" type="button">${task.status === 'active' ? '终止' : '恢复'}</button>
          <button class="chip" type="button">回退</button>
          <button class="chip" type="button">详情</button>
        </div>
      </div>
    `).join('');
  }

  $('#runnerSwitch')?.addEventListener('change', (e) => {
    const on = e.target.checked;
    $('#runnerTitle').textContent = on ? '插件自动跑：开' : '插件自动跑：关';
    $('#runnerHint').textContent = on ? '扩展会排队执行 active 任务' : '扩展即时停止，不再自动推进';
  });

  $('#previewCreateTask')?.addEventListener('click', () => {
    alert('预览模式：真实 App 会调用 POST /api/tasks 创建任务。');
  });

  renderCompanies(companies);
  renderTasks();
  const tab = new URL(location.href).searchParams.get('tab') || 'dial';
  if (['dial', 'tasks', 'companies', 'settings'].includes(tab)) switchTab(tab);
})();
