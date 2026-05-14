/**
 * LP Parser - DataTables JSON Loader
 * Loads table data from JSON files and renders with DataTables
 */

const LPTables = {
  dataPath: 'data/tables/',
  charts: {},

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
    const sourceRows = Array.isArray(data.data) ? data.data : [];
    const filteredRows = typeof options.filterFn === 'function'
      ? sourceRows.filter((row) => options.filterFn(row, data))
      : sourceRows;

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
    if (filteredRows.length === 0) {
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
    if (tableEl && filteredRows.length > 0) {
      const columns = data.columns.map(col => {
        if (col.render_as_html) {
          return { ...col, render: (data) => data };
        }
        return col;
      });

      new DataTable(tableEl, {
        data: filteredRows,
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

      const getDayDate = (day) => {
        if (!day || typeof day !== 'object') return '';
        if (typeof day.date === 'string' && day.date) return day.date;
        if (typeof day.title === 'string') {
          const match = day.title.match(/\d{4}-\d{2}-\d{2}/);
          return match ? match[0] : '';
        }
        return '';
      };

      const getDayLabel = (day, index) => {
        if (day && typeof day === 'object') {
          if (typeof day.label === 'string' && day.label) return day.label;
          const date = getDayDate(day);
          if (date) return date;
          if (typeof day.title === 'string' && day.title) return day.title;
        }
        return String(index + 1);
      };

      // Build dropdown
      let html = '<div class="day-selector">';
      html += '<label for="' + containerId + '-select">Select Date: </label>';
      html += '<select id="' + containerId + '-select" class="date-dropdown">';
      days.forEach((day, index) => {
        const dateValue = getDayLabel(day, index);
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

      // Show first day by default, or today's date when requested
      let selectedIndex = 0;
      if (options.defaultToToday === true) {
        const today = new Date().toISOString().slice(0, 10);
        const foundIndex = days.findIndex((day) => {
          if (day && typeof day === 'object' && typeof day.period_start === 'string') {
            const start = day.period_start;
            const endExclusive = typeof day.period_end === 'string' && day.period_end ? day.period_end : '9999-12-31';
            return today >= start && today < endExclusive;
          }
          return getDayDate(day) === today;
        });
        if (foundIndex >= 0) selectedIndex = foundIndex;
      }

      select.value = String(selectedIndex);
      showDay(selectedIndex);

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
   * Render Prediction distribution charts.
   * - weekday fee distribution
   * - weekday trading days (fees > 0)
   * - fees per day, rolling avg, and capital per day
   */
  async loadPredictionWeekdayDistribution(containerId, jsonFile) {
    const container = document.getElementById(containerId);
    if (!container) return;

    try {
      const response = await fetch(this.dataPath + jsonFile);
      if (!response.ok) throw new Error(`Failed to load ${jsonFile}`);

      const payload = await response.json();
      const rows = Array.isArray(payload.data) ? payload.data : [];
      if (rows.length === 0) {
        container.innerHTML = '<p>No data available</p>';
        return;
      }

      const parseDateUtc = (dateStr) => {
        if (typeof dateStr !== 'string' || !dateStr) return null;
        const d = new Date(dateStr + 'T00:00:00Z');
        return Number.isNaN(d.getTime()) ? null : d;
      };

      const formatDateUtc = (date) => date.toISOString().slice(0, 10);

      const parseFee = (row) => {
        if (typeof row.daily_raw === 'number') return row.daily_raw;
        if (typeof row.daily_income === 'string') {
          const cleaned = row.daily_income.replace(/,/g, '');
          const value = Number(cleaned);
          return Number.isFinite(value) ? value : 0;
        }
        return 0;
      };

      const parseCapital = (row) => {
        if (typeof row.portfolio_value_raw === 'number') return row.portfolio_value_raw;
        return 0;
      };

      const formatMoney = (value) => Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });

      const rollingAverage = (values, windowSize) => values.map((_, index) => {
        const start = Math.max(0, index - windowSize + 1);
        const slice = values.slice(start, index + 1);
        if (slice.length === 0) return 0;
        const total = slice.reduce((sum, value) => sum + value, 0);
        return total / slice.length;
      });

      const utcDayMs = 24 * 60 * 60 * 1000;

      const weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
      const totalFees = [0, 0, 0, 0, 0, 0, 0];
      const counts = [0, 0, 0, 0, 0, 0, 0];
      const currentWeekFees = [0, 0, 0, 0, 0, 0, 0];
      const tradingDayCounts = [0, 0, 0, 0, 0, 0, 0];
      const zeroFeeDayCounts = [0, 0, 0, 0, 0, 0, 0];

      const datedRows = rows
        .map((row) => ({ row, date: parseDateUtc(row.date) }))
        .filter((entry) => entry.date !== null)
        .sort((a, b) => a.date - b.date);

      if (datedRows.length === 0) {
        container.innerHTML = '<p>No data available</p>';
        return;
      }

      const rowByDate = new Map(
        datedRows.map(({ row, date }) => [formatDateUtc(date), { row, date }])
      );

      const firstDate = datedRows[0].date;
      const latestDate = datedRows[datedRows.length - 1].date;
      const latestDay = latestDate.getUTCDay();
      const daysSinceMonday = (latestDay + 6) % 7;
      const weekStart = new Date(Date.UTC(
        latestDate.getUTCFullYear(),
        latestDate.getUTCMonth(),
        latestDate.getUTCDate() - daysSinceMonday
      ));
      const weekEnd = new Date(weekStart.getTime() + (7 * 24 * 60 * 60 * 1000));

      const timelineEntries = [];
      let lastCapital = parseCapital(datedRows[0].row);

      for (let time = firstDate.getTime(); time <= latestDate.getTime(); time += utcDayMs) {
        const date = new Date(time);
        const dateKey = formatDateUtc(date);
        const existingEntry = rowByDate.get(dateKey);
        const fee = existingEntry ? parseFee(existingEntry.row) : 0;

        if (existingEntry) {
          lastCapital = parseCapital(existingEntry.row);
        }

        timelineEntries.push({
          date,
          dateKey,
          fee,
          capital: lastCapital,
        });
      }

      const labels = [];
      const feesByDay = [];
      const capitalByDay = [];

      timelineEntries.forEach(({ date, dateKey, fee, capital }) => {
        const weekdayIndex = (date.getUTCDay() + 6) % 7;

        totalFees[weekdayIndex] += fee;
        counts[weekdayIndex] += 1;

        if (fee > 0) {
          tradingDayCounts[weekdayIndex] += 1;
        } else if (fee === 0) {
          zeroFeeDayCounts[weekdayIndex] += 1;
        }

        if (date >= weekStart && date < weekEnd) {
          currentWeekFees[weekdayIndex] += fee;
        }

        labels.push(dateKey);
        feesByDay.push(fee);
        capitalByDay.push(capital);
      });

      const avgFees = totalFees.map((total, idx) => (counts[idx] > 0 ? total / counts[idx] : 0));
      const rollingFeeAvg = rollingAverage(feesByDay, 7);

      const feeDistributionChartId = `${containerId}-fee-distribution-chart`;
      const weekdayChartId = `${containerId}-weekday-chart`;
      const feesChartId = `${containerId}-fees-chart`;
      container.innerHTML = '<h3>Weekday Fee Distribution</h3>' +
        '<div class="prediction-chart-wrap"><canvas id="' + feeDistributionChartId + '"></canvas></div>' +
        '<h3>Weekday Trading Days</h3>' +
        '<div class="prediction-chart-wrap"><canvas id="' + weekdayChartId + '"></canvas></div>' +
        '<h3>Fees Per Day</h3>' +
        '<div class="prediction-chart-wrap"><canvas id="' + feesChartId + '"></canvas></div>';

      if (this.charts[feeDistributionChartId]) {
        this.charts[feeDistributionChartId].destroy();
      }

      if (this.charts[weekdayChartId]) {
        this.charts[weekdayChartId].destroy();
      }

      if (this.charts[feesChartId]) {
        this.charts[feesChartId].destroy();
      }

      const feeDistributionCtx = document.getElementById(feeDistributionChartId);
      this.charts[feeDistributionChartId] = new Chart(feeDistributionCtx, {
        type: 'bar',
        data: {
          labels: weekdays,
          datasets: [
            {
              label: 'Avg fees',
              data: avgFees,
              backgroundColor: 'rgba(34, 197, 94, 0.8)',
              borderColor: 'rgba(22, 163, 74, 1)',
              borderWidth: 1
            },
            {
              label: 'Current weekday fees',
              data: currentWeekFees,
              backgroundColor: 'rgba(245, 158, 11, 0.85)',
              borderColor: 'rgba(217, 119, 6, 1)',
              borderWidth: 1
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'top'
            },
            tooltip: {
              callbacks: {
                label: function(context) {
                  const value = Number(context.raw || 0);
                  return `${context.dataset.label}: ${value.toFixed(2)}`;
                }
              }
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              ticks: {
                callback: function(value) {
                  return Number(value).toFixed(0);
                }
              }
            }
          }
        }
      });

      const weekdayCtx = document.getElementById(weekdayChartId);
      this.charts[weekdayChartId] = new Chart(weekdayCtx, {
        type: 'bar',
        data: {
          labels: weekdays,
          datasets: [
            {
              label: 'Trading days with fees > 0',
              data: tradingDayCounts,
              backgroundColor: 'rgba(34, 197, 94, 0.8)',
              borderColor: 'rgba(22, 163, 74, 1)',
              borderWidth: 1,
              borderRadius: 6,
              borderSkipped: false,
              categoryPercentage: 0.58,
              barPercentage: 0.82,
              maxBarThickness: 24
            },
            {
              label: 'Trading days with fees = 0',
              data: zeroFeeDayCounts,
              backgroundColor: 'rgba(239, 68, 68, 0.8)',
              borderColor: 'rgba(220, 38, 38, 1)',
              borderWidth: 1,
              borderRadius: 6,
              borderSkipped: false,
              categoryPercentage: 0.58,
              barPercentage: 0.82,
              maxBarThickness: 24
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          layout: {
            padding: {
              top: 12,
              bottom: 10
            }
          },
          plugins: {
            legend: {
              position: 'top'
            },
            tooltip: {
              callbacks: {
                label: function(context) {
                  return `${context.dataset.label}: ${Number(context.raw || 0).toFixed(0)}`;
                }
              }
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              grace: '10%',
              ticks: {
                precision: 0
              }
            }
          }
        }
      });

      const feesCtx = document.getElementById(feesChartId);
      this.charts[feesChartId] = new Chart(feesCtx, {
        type: 'line',
        data: {
          labels,
          datasets: [
            {
              label: 'Fees per day',
              data: feesByDay,
              yAxisID: 'yFees',
              borderColor: 'rgba(37, 99, 235, 1)',
              backgroundColor: 'rgba(37, 99, 235, 0.18)',
              borderWidth: 2,
              pointRadius: 2,
              tension: 0.25,
              fill: false
            },
            {
              label: 'Rolling fee avg (7d)',
              data: rollingFeeAvg,
              yAxisID: 'yFees',
              borderColor: 'rgba(249, 115, 22, 1)',
              backgroundColor: 'rgba(249, 115, 22, 0)',
              borderWidth: 2,
              borderDash: [6, 6],
              pointRadius: 0,
              tension: 0.25,
              fill: false
            },
            {
              label: 'Capital per day',
              data: capitalByDay,
              yAxisID: 'yCapital',
              borderColor: 'rgba(34, 197, 94, 1)',
              backgroundColor: 'rgba(34, 197, 94, 0)',
              borderWidth: 2,
              borderDash: [8, 6],
              pointRadius: 0,
              tension: 0.2,
              fill: false
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'top'
            },
            tooltip: {
              callbacks: {
                label: function(context) {
                  const value = Number(context.raw || 0);
                  return `${context.dataset.label}: ${formatMoney(value)}`;
                }
              }
            }
          },
          scales: {
            x: {
              ticks: {
                maxRotation: 45,
                minRotation: 45
              }
            },
            yFees: {
              type: 'linear',
              position: 'left',
              beginAtZero: true,
              title: {
                display: true,
                text: 'Fees'
              },
              ticks: {
                callback: function(value) {
                  return formatMoney(value);
                }
              }
            },
            yCapital: {
              type: 'linear',
              position: 'right',
              beginAtZero: true,
              grid: {
                drawOnChartArea: false
              },
              title: {
                display: true,
                text: 'Capital'
              },
              ticks: {
                callback: function(value) {
                  return formatMoney(value);
                }
              }
            }
          }
        }
      });
    } catch (error) {
      console.error(`Error loading prediction distribution ${jsonFile}:`, error);
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
    const presetStorageKey = 'lp-parser:rebalance-presets:v1';
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

    const baseTotalUsdt = rows.reduce((sum, row) => sum + row.baseUsdt, 0);
    const baseTotalUsdc = rows.reduce((sum, row) => sum + row.baseUsdc, 0);

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
    html += '<label for="rebalance-preset-select">Saved View</label>';
    html += '<select id="rebalance-preset-select"><option value="">Current</option></select>';
    html += '<button type="button" id="rebalance-save-preset" class="rebalance-action-btn">Save View</button>';
    html += '<button type="button" id="rebalance-export-md" class="rebalance-action-btn">Export Markdown</button>';
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
    const presetSelect = container.querySelector('#rebalance-preset-select');
    const savePresetButton = container.querySelector('#rebalance-save-preset');
    const exportMarkdownButton = container.querySelector('#rebalance-export-md');
    const pctInputs = container.querySelectorAll('input[data-role="pct-input"]');
    let lastComputedRows = [];
    let lastComputedTotals = { usdt: 0, usdc: 0, pct: 0 };

    // Range curve should always be the default mode on initial load.
    if (modeInput) {
      modeInput.value = 'curve';
    }

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

    const loadPresets = () => {
      try {
        const raw = localStorage.getItem(presetStorageKey);
        const parsed = raw ? JSON.parse(raw) : {};
        return parsed && typeof parsed === 'object' ? parsed : {};
      } catch (error) {
        return {};
      }
    };

    const savePresets = (presets) => {
      localStorage.setItem(presetStorageKey, JSON.stringify(presets));
    };

    const getCurrentSettings = () => ({
      total: Number(totalInput?.value || 0),
      mode: modeInput?.value || 'curve',
      percentages: rows.map((row, idx) => {
        const pctInput = container.querySelector(`input[data-role="pct-input"][data-index="${idx}"]`);
        return {
          rung: row.rung,
          pct: Number(pctInput?.value || 0)
        };
      })
    });

    const renderPresetOptions = (selectedName = '') => {
      if (!presetSelect) return;
      const presets = loadPresets();
      const names = Object.keys(presets).sort((a, b) => a.localeCompare(b));
      let options = '<option value="">Current</option>';
      names.forEach((name) => {
        const selected = name === selectedName ? ' selected' : '';
        options += `<option value="${this.escapeHtml(name)}"${selected}>${this.escapeHtml(name)}</option>`;
      });
      presetSelect.innerHTML = options;
    };

    const applyPreset = (name) => {
      if (!name) return;
      const presets = loadPresets();
      const preset = presets[name];
      if (!preset) return;

      if (totalInput && typeof preset.total === 'number') {
        totalInput.value = preset.total.toFixed(2);
      }
      if (modeInput && typeof preset.mode === 'string') {
        modeInput.value = preset.mode;
      }
      (preset.percentages || []).forEach((entry) => {
        const idx = rows.findIndex((row) => row.rung === entry.rung);
        if (idx >= 0) {
          const pctInput = container.querySelector(`input[data-role="pct-input"][data-index="${idx}"]`);
          if (pctInput) {
            pctInput.value = Number(entry.pct || 0).toFixed(4);
          }
        }
      });
      recalc();
    };

    const exportMarkdown = async () => {
      const modeLabel = modeInput ? modeInput.options[modeInput.selectedIndex].text : 'Range Curve';
      const lines = [];
      lines.push('## Re-balance');
      lines.push('');
      lines.push(`- Total Amount: ${fmtMoney(Number(totalInput?.value || 0))}`);
      lines.push(`- Ratio Mode: ${modeLabel}`);
      lines.push('');
      lines.push('| Rung | Name | USDT | USDC | Range | % of total |');
      lines.push('| --- | --- | ---: | ---: | --- | ---: |');
      lastComputedRows.forEach((row) => {
        lines.push(`| ${row.rung} | ${row.name} | ${fmtMoney(row.usdt)} | ${fmtMoney(row.usdc)} | ${row.rangeLower} to ${row.rangeUpper} | ${row.pct.toFixed(4)} |`);
      });
      lines.push(`| **Total** |  | **${fmtMoney(lastComputedTotals.usdt)}** | **${fmtMoney(lastComputedTotals.usdc)}** |  | **${lastComputedTotals.pct.toFixed(2)}%** |`);
      const markdown = lines.join('\n');

      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(markdown);
      }

      if (exportMarkdownButton) {
        const originalText = exportMarkdownButton.textContent;
        exportMarkdownButton.textContent = 'Copied';
        setTimeout(() => {
          exportMarkdownButton.textContent = originalText;
        }, 1200);
      }
    };

    const promptForPresetName = () => new Promise((resolve) => {
      const existing = container.querySelector('.rebalance-modal-backdrop');
      if (existing) existing.remove();

      const modal = document.createElement('div');
      modal.className = 'rebalance-modal-backdrop';
      modal.innerHTML = `
        <div class="rebalance-modal" role="dialog" aria-modal="true" aria-labelledby="rebalance-save-title">
          <h3 id="rebalance-save-title">Save current settings</h3>
          <input type="text" class="rebalance-modal-input" placeholder="Preset name" />
          <div class="rebalance-modal-actions">
            <button type="button" class="rebalance-action-btn" data-role="cancel">Cancel</button>
            <button type="button" class="rebalance-action-btn" data-role="save">Save</button>
          </div>
        </div>
      `;

      const cleanup = (value) => {
        modal.remove();
        resolve(value);
      };

      const input = modal.querySelector('.rebalance-modal-input');
      const cancel = modal.querySelector('[data-role="cancel"]');
      const save = modal.querySelector('[data-role="save"]');

      cancel.addEventListener('click', () => cleanup(''));
      save.addEventListener('click', () => cleanup((input.value || '').trim()));
      input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') cleanup((input.value || '').trim());
        if (event.key === 'Escape') cleanup('');
      });
      modal.addEventListener('click', (event) => {
        if (event.target === modal) cleanup('');
      });

      container.appendChild(modal);
      input.focus();
    });

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
      lastComputedRows = [];

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

        lastComputedRows.push({
          rung: row.rung,
          name: row.name,
          usdt,
          usdc,
          rangeLower: row.rangeLower,
          rangeUpper: row.rangeUpper,
          pct
        });
      });

      lastComputedTotals = { usdt: sumUsdt, usdc: sumUsdc, pct: sumPct };

      const totalUsdtEl = container.querySelector('#rebalance-total-usdt strong');
      const totalUsdcEl = container.querySelector('#rebalance-total-usdc strong');
      const totalPctEl = container.querySelector('#rebalance-total-pct strong');
      if (totalUsdtEl) totalUsdtEl.innerHTML = formatAssetWithDiff(sumUsdt, baseTotalUsdt);
      if (totalUsdcEl) totalUsdcEl.innerHTML = formatAssetWithDiff(sumUsdc, baseTotalUsdc);
      if (totalPctEl) totalPctEl.textContent = fmtPct(sumPct);
    };

    totalInput.addEventListener('input', recalc);
    modeInput.addEventListener('change', recalc);
    pctInputs.forEach((input) => input.addEventListener('input', recalc));
    if (presetSelect) {
      presetSelect.addEventListener('change', function() {
        applyPreset(this.value);
      });
    }
    if (savePresetButton) {
      savePresetButton.addEventListener('click', async () => {
        const trimmed = await promptForPresetName();
        if (!trimmed) return;
        const presets = loadPresets();
        presets[trimmed] = getCurrentSettings();
        savePresets(presets);
        renderPresetOptions(trimmed);
      });
    }
    if (exportMarkdownButton) {
      exportMarkdownButton.addEventListener('click', () => {
        exportMarkdown().catch(() => {
          exportMarkdownButton.textContent = 'Export failed';
          setTimeout(() => {
            exportMarkdownButton.textContent = 'Export Markdown';
          }, 1200);
        });
      });
    }

    renderPresetOptions();
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
