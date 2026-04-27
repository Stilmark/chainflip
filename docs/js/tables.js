/**
 * LP Parser - DataTables JSON Loader
 * Loads table data from JSON files and renders with DataTables
 */

const LPTables = {
  dataPath: 'data/tables/',

  /**
   * Load JSON data and initialize a DataTable
   */
  async loadTable(containerId, jsonFile, options = {}) {
    const container = document.getElementById(containerId);
    if (!container) return;

    try {
      const response = await fetch(this.dataPath + jsonFile);
      if (!response.ok) throw new Error(`Failed to load ${jsonFile}`);
      
      const data = await response.json();
      this.renderTable(container, data, options);
    } catch (error) {
      console.error(`Error loading table ${jsonFile}:`, error);
      container.innerHTML = '<p class="error">Failed to load data</p>';
    }
  },

  /**
   * Render summary status bar
   */
  renderSummary(container, summary, title, hideTitle = false) {
    if (!summary || summary.length === 0) return;

    let html = '<div class="status-bar">';
    if (title && !hideTitle) {
      html += `<div class="status-bar-title">${this.escapeHtml(title)}</div>`;
    }
    summary.forEach(item => {
      html += `<div class="status-item">
        <span class="status-label">${this.escapeHtml(item.label)}</span>
        <span class="status-value">${this.escapeHtml(item.value)}</span>
      </div>`;
    });
    html += '</div>';
    return html;
  },

  /**
   * Render a table with DataTables
   */
  renderTable(container, data, options = {}) {
    let html = '';

    // Render summary/status bar if present
    if (data.summary) {
      html += this.renderSummary(container, data.summary, data.subtitle || data.title, data.hide_summary_title);
    }

    // Render table title if present
    if (data.table_title) {
      html += `<h3>${this.escapeHtml(data.table_title)}</h3>`;
    }

    // Check for error
    if (data.error) {
      html += `<p class="error">${this.escapeHtml(data.error)}</p>`;
      container.innerHTML = html;
      return;
    }

    // Check for data
    if (!data.data || data.data.length === 0) {
      html += '<p>No data available</p>';
      container.innerHTML = html;
      return;
    }

    // Create table element
    const tableId = container.id + '-table';
    html += `<table id="${tableId}" class="data-table display"><thead>`;
    
    // Check for header groups (two-row header with colspan)
    if (data.header_groups && data.header_groups.length > 0) {
      // First row: group headers with colspan
      html += '<tr>';
      data.header_groups.forEach(group => {
        if (group.title) {
          html += `<th colspan="${group.colspan}" class="header-group">${this.escapeHtml(group.title)}</th>`;
        } else {
          // Empty group - output individual cells for first columns
          for (let i = 0; i < group.colspan; i++) {
            html += `<th rowspan="2">${this.escapeHtml(data.columns[i].title)}</th>`;
          }
        }
      });
      html += '</tr>';
      // Second row: individual column headers (skip first N that have rowspan)
      html += '<tr>';
      const skipCount = data.header_groups[0]?.colspan || 0;
      data.columns.slice(skipCount).forEach(col => {
        html += `<th>${this.escapeHtml(col.title)}</th>`;
      });
      html += '</tr>';
    } else {
      // Single row header
      html += '<tr>';
      data.columns.forEach(col => {
        html += `<th>${this.escapeHtml(col.title)}</th>`;
      });
      html += '</tr>';
    }
    html += '</thead><tbody></tbody>';

    // Add footer if present
    if (data.footer) {
      html += '<tfoot><tr>';
      data.columns.forEach(col => {
        const val = data.footer[col.data] ?? '';
        html += `<td><strong>${this.escapeHtml(String(val))}</strong></td>`;
      });
      html += '</tr></tfoot>';
    }

    html += '</table>';

    // Add note if present
    if (data.note) {
      html += `<p class="table-note"><em>${this.escapeHtml(data.note)}</em></p>`;
    }

    // Add legend if present
    if (data.legend) {
      html += `<p class="table-legend"><em>${this.escapeHtml(data.legend)}</em></p>`;
    }

    // Add distribution table if present
    if (data.distribution_columns && data.distribution_data && data.distribution_data.length > 0) {
      const distTableId = container.id + '-distribution-table';
      html += '<h3>Price Distribution</h3>';
      html += `<table id="${distTableId}" class="data-table display"><thead><tr>`;
      data.distribution_columns.forEach(col => {
        html += `<th>${this.escapeHtml(col.title)}</th>`;
      });
      html += '</tr></thead><tbody></tbody></table>';
    }

    container.innerHTML = html;

    // Initialize DataTable
    const tableEl = document.getElementById(tableId);
    if (tableEl && data.data.length > 0) {
      const columns = data.columns.map(col => {
        if (col.render_as_html) {
          return { ...col, render: (data) => data };
        }
        return col;
      });

      new DataTable(tableEl, {
        data: data.data,
        columns: columns,
        paging: options.paging ?? false,
        searching: options.searching ?? false,
        info: options.info ?? false,
        order: options.order ?? [],
        ordering: true,
        columnDefs: [
          { targets: '_all', orderable: true }
        ]
      });
    }

    // Initialize distribution DataTable if present
    if (data.distribution_columns && data.distribution_data && data.distribution_data.length > 0) {
      const distTableId = container.id + '-distribution-table';
      const distTableEl = document.getElementById(distTableId);
      if (distTableEl) {
        // Mark columns with HTML content to not escape
        const distColumns = data.distribution_columns.map(col => {
          if (col.data === 'volume_pct' || col.data === 'fees_pct') {
            return { ...col, render: (data) => data };
          }
          return col;
        });
        new DataTable(distTableEl, {
          data: data.distribution_data,
          columns: distColumns,
          paging: false,
          searching: false,
          info: false,
          order: [[0, 'desc']],
          ordering: true,
          columnDefs: [
            { targets: '_all', orderable: true }
          ]
        });
      }
    }
  },

  /**
   * Render multiple day tables (for by-day views)
   */
  async loadDayTables(containerId, jsonFile, options = {}) {
    const container = document.getElementById(containerId);
    if (!container) return;

    try {
      const response = await fetch(this.dataPath + jsonFile);
      if (!response.ok) throw new Error(`Failed to load ${jsonFile}`);
      
      const data = await response.json();
      
      if (data.error) {
        container.innerHTML = `<p class="error">${this.escapeHtml(data.error)}</p>`;
        return;
      }

      // Handle status-by-day format (array of days with tables)
      if (data.days && Array.isArray(data.days)) {
        let html = '';
        data.days.forEach((day, index) => {
          const dayContainerId = `${containerId}-day-${index}`;
          html += `<div id="${dayContainerId}" class="day-section"></div>`;
        });
        container.innerHTML = html;

        data.days.forEach((day, index) => {
          const dayContainer = document.getElementById(`${containerId}-day-${index}`);
          if (dayContainer) {
            this.renderTable(dayContainer, day, options);
          }
        });
      } else {
        // Single table format
        this.renderTable(container, data, options);
      }
    } catch (error) {
      console.error(`Error loading day tables ${jsonFile}:`, error);
      container.innerHTML = '<p class="error">Failed to load data</p>';
    }
  },

  /**
   * Load day tables with dropdown selector (shows one day at a time)
   */
  async loadDaySelector(containerId, jsonFile, options = {}) {
    const container = document.getElementById(containerId);
    if (!container) return;

    try {
      const response = await fetch(this.dataPath + jsonFile);
      if (!response.ok) throw new Error(`Failed to load ${jsonFile}`);
      
      const data = await response.json();
      
      if (data.error) {
        container.innerHTML = `<p class="error">${this.escapeHtml(data.error)}</p>`;
        return;
      }

      if (!data.days || !Array.isArray(data.days) || data.days.length === 0) {
        container.innerHTML = '<p>No data available</p>';
        return;
      }

      // Sort days descending by date (most recent first)
      const days = [...data.days].sort((a, b) => {
        const dateA = a.date || (a.title && a.title.match(/\d{4}-\d{2}-\d{2}/)?.[0]) || '';
        const dateB = b.date || (b.title && b.title.match(/\d{4}-\d{2}-\d{2}/)?.[0]) || '';
        return dateB.localeCompare(dateA);
      });

      // Build dropdown
      let html = '<div class="day-selector">';
      html += '<label for="' + containerId + '-select">Select Date: </label>';
      html += '<select id="' + containerId + '-select" class="date-dropdown">';
      days.forEach((day, index) => {
        const dateMatch = day.title.match(/\d{4}-\d{2}-\d{2}/);
        const dateValue = dateMatch ? dateMatch[0] : day.title;
        html += `<option value="${index}">${dateValue}</option>`;
      });
      html += '</select></div>';
      html += `<div id="${containerId}-content"></div>`;
      
      container.innerHTML = html;

      const self = this;
      const select = document.getElementById(containerId + '-select');
      const contentDiv = document.getElementById(containerId + '-content');

      function showDay(index) {
        contentDiv.innerHTML = '';
        self.renderTable(contentDiv, days[index], options);
      }

      // Show first (most recent) day
      showDay(0);

      // Handle dropdown change
      select.addEventListener('change', function() {
        showDay(parseInt(this.value));
      });

    } catch (error) {
      console.error(`Error loading day selector ${jsonFile}:`, error);
      container.innerHTML = '<p class="error">Failed to load data</p>';
    }
  },

  /**
   * Load and render the re-balance calculator.
   */
  async loadRebalanceCalculator(containerId, jsonFile) {
    const container = document.getElementById(containerId);
    if (!container) return;

    try {
      const response = await fetch(this.dataPath + jsonFile);
      if (!response.ok) throw new Error(`Failed to load ${jsonFile}`);

      const payload = await response.json();
      this.renderRebalanceCalculator(container, payload);
    } catch (error) {
      console.error(`Error loading rebalance calculator ${jsonFile}:`, error);
      container.innerHTML = '<p class="error">Failed to load data</p>';
    }
  },

  renderRebalanceCalculator(container, payload) {
    if (!payload || !Array.isArray(payload.rows) || payload.rows.length === 0) {
      container.innerHTML = '<p>No data available</p>';
      return;
    }

    const fmtMoney = (v) => Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const fmtPct = (v) => `${Number(v || 0).toFixed(2)}%`;
    const deltaClass = (d) => (d > 0 ? 'delta-pos' : (d < 0 ? 'delta-neg' : 'delta-flat'));
    const formatAssetWithDiff = (value, baseline) => {
      let diff = Number(value || 0) - Number(baseline || 0);
      if (Math.abs(diff) < 0.005) diff = 0;
      const sign = diff > 0 ? '+' : '';
      return `${fmtMoney(value)} <span class="delta-tail ${deltaClass(diff)}">(${sign}${fmtMoney(diff)})</span>`;
    };

    const rows = payload.rows.map((r) => ({
      rung: r.rung,
      name: r.name,
      rangeLower: r.range_lower,
      rangeUpper: r.range_upper,
      ratio: Number(r.current_ratio || 0),
      liveRatio: Number(r.live_ratio || 0),
      sharePct: Number(r.share_pct || 0),
      usdt: Number(r.current_usdt || 0),
      usdc: Number(r.current_usdc || 0),
      baseUsdt: Number(r.current_usdt || 0),
      baseUsdc: Number(r.current_usdc || 0)
    }));

    const defaultTotal = Number(payload.total_current || rows.reduce((s, r) => s + r.usdt + r.usdc, 0));

    let html = '';
    html += '<div class="rebalance-controls">';
    html += '<label for="rebalance-total-input">Total Amount</label>';
    html += `<input id="rebalance-total-input" type="number" step="0.01" value="${defaultTotal.toFixed(2)}" />`;
    html += '<label for="rebalance-ratio-mode">Ratio Mode</label>';
    html += '<select id="rebalance-ratio-mode">';
    html += '<option value="curve" selected>Range Curve (pool price + bounds)</option>';
    html += '<option value="gui">GUI Match (ref price 1.000500250125)</option>';
    html += '<option value="live">Live Split (current holdings)</option>';
    html += '<option value="linear">Linear Range Distance (experimental)</option>';
    html += '</select>';
    html += '</div>';

    html += '<table class="data-table display rebalance-table">';
    html += '<thead><tr>';
    html += '<th>Rung</th><th>Name</th><th>USDT</th><th>USDC</th><th>Range</th><th>% of total</th>';
    html += '</tr></thead><tbody>';

    rows.forEach((row, index) => {
      html += '<tr>';
      html += `<td>${this.escapeHtml(row.rung)}</td>`;
      html += `<td>${this.escapeHtml(row.name)}</td>`;
      html += `<td data-role="usdt" data-index="${index}">${formatAssetWithDiff(row.usdt, row.baseUsdt)}</td>`;
      html += `<td data-role="usdc" data-index="${index}">${formatAssetWithDiff(row.usdc, row.baseUsdc)}</td>`;
      html += `<td>${this.escapeHtml(row.rangeLower)} to ${this.escapeHtml(row.rangeUpper)}</td>`;
      html += '<td>';
      html += `<input data-role="pct-input" data-index="${index}" type="number" step="0.0001" value="${row.sharePct.toFixed(4)}" class="rebalance-pct-input" />`;
      html += '</td>';
      html += '</tr>';
    });

    html += '</tbody><tfoot><tr>';
    html += '<td><strong>Total</strong></td>';
    html += '<td></td>';
    html += '<td id="rebalance-total-usdt"><strong>0.00</strong></td>';
    html += '<td id="rebalance-total-usdc"><strong>0.00</strong></td>';
    html += '<td></td>';
    html += '<td id="rebalance-total-pct"><strong>100.00%</strong></td>';
    html += '</tr></tfoot></table>';

    if (payload.footnote) {
      html += `<p class="table-note"><em>${this.escapeHtml(payload.footnote)}</em></p>`;
    }

    container.innerHTML = html;

    const totalInput = container.querySelector('#rebalance-total-input');
    const modeInput = container.querySelector('#rebalance-ratio-mode');
    const pctInputs = container.querySelectorAll('input[data-role="pct-input"]');

    const ratioFromPriceAndRange = (price, row) => {
      const p = Number(price || 0);
      const lo = Number(row.rangeLower || 0);
      const hi = Number(row.rangeUpper || 0);
      if (!(p > 0 && lo > 0 && hi > 0)) {
        return row.ratio > 0 ? row.ratio : 1;
      }

      const lower = Math.min(lo, hi);
      const upper = Math.max(lo, hi);
      const sp = Math.sqrt(p);
      const sl = Math.sqrt(lower);
      const su = Math.sqrt(upper);

      let usdt;
      let usdc;
      if (p <= lower) {
        usdt = (1 / sl) - (1 / su);
        usdc = 0;
      } else if (p >= upper) {
        usdt = 0;
        usdc = su - sl;
      } else {
        usdt = (1 / sp) - (1 / su);
        usdc = sp - sl;
      }

      if (!(usdc > 0)) {
        return 1000000;
      }
      return usdt / usdc;
    };

    const getModeRatio = (row) => {
      const mode = modeInput?.value || 'curve';
      if (mode === 'gui') {
        return ratioFromPriceAndRange(1.000500250125, row);
      }
      if (mode === 'live') {
        return row.liveRatio > 0 ? row.liveRatio : (row.ratio > 0 ? row.ratio : 1);
      }
      if (mode === 'linear') {
        const p = Number(payload.pool_ratio || 0);
        const lo = Number(row.rangeLower || 0);
        const hi = Number(row.rangeUpper || 0);
        if (p > 0 && lo > 0 && hi > 0 && hi > lo) {
          if (p <= lo) return 0.000001;
          if (p >= hi) return 1000000;
          return (p - lo) / (hi - p);
        }
      }
      return row.ratio > 0 ? row.ratio : 1;
    };

    const recalc = () => {
      const total = Number(totalInput.value || 0);

      let sumUsdt = 0;
      let sumUsdc = 0;
      let sumPct = 0;

      rows.forEach((row, idx) => {
        const pctInput = container.querySelector(`input[data-role="pct-input"][data-index="${idx}"]`);
        const pct = Number(pctInput?.value || 0);
        sumPct += pct;

        const target = total * (pct / 100);
        const ratio = getModeRatio(row);
        const usdt = target * (ratio / (1 + ratio));
        const usdc = target / (1 + ratio);

        sumUsdt += usdt;
        sumUsdc += usdc;

        const usdtCell = container.querySelector(`td[data-role="usdt"][data-index="${idx}"]`);
        const usdcCell = container.querySelector(`td[data-role="usdc"][data-index="${idx}"]`);
        if (usdtCell) usdtCell.innerHTML = formatAssetWithDiff(usdt, row.baseUsdt);
        if (usdcCell) usdcCell.innerHTML = formatAssetWithDiff(usdc, row.baseUsdc);
      });

      const totalUsdtEl = container.querySelector('#rebalance-total-usdt strong');
      const totalUsdcEl = container.querySelector('#rebalance-total-usdc strong');
      const totalPctEl = container.querySelector('#rebalance-total-pct strong');
      if (totalUsdtEl) totalUsdtEl.textContent = fmtMoney(sumUsdt);
      if (totalUsdcEl) totalUsdcEl.textContent = fmtMoney(sumUsdc);
      if (totalPctEl) totalPctEl.textContent = fmtPct(sumPct);
    };

    totalInput.addEventListener('input', recalc);
    modeInput.addEventListener('change', recalc);
    pctInputs.forEach((input) => input.addEventListener('input', recalc));

    recalc();
  },

  /**
   * Escape HTML entities
   */
  escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }
};

// Export for use
window.LPTables = LPTables;
