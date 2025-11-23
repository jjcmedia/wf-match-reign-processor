// Tiny Chart.js initializer for canvas.wf-chart elements
// Expects Chart.js to be loaded as 'chartjs' handle (plugin registers CDN if not present).
(function(){
  function initCharts() {
    try {
      if ( typeof Chart === 'undefined' ) return;
      var els = document.querySelectorAll('canvas.wf-chart');
      els.forEach(function(canvas){
        try {
          var raw = canvas.getAttribute('data-values') || '[]';
          var data = JSON.parse(raw);
          if ( ! Array.isArray(data) || data.length === 0 ) return;
          var ctx = canvas.getContext('2d');
          new Chart(ctx, {
            type: 'bar',
            data: {
              labels: data.map(function(_, i){ return i + 1; }),
              datasets: [{
                label: 'Trend',
                data: data,
                backgroundColor: 'rgba(255,213,79,0.12)',
                borderColor: 'rgba(255,213,79,0.95)',
                borderWidth: 1,
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              scales: { x: { display: false }, y: { display: false } },
              plugins: { legend: { display: false }, tooltip: { enabled: true } },
              elements: { point: { radius: 0 } }
            }
          });
        } catch (e) { console.warn('wf chart failed', e); }
      });
    } catch(e){ console.warn('wf chart init error', e); }
  }

  if ( document.readyState === 'loading' ) {
    document.addEventListener('DOMContentLoaded', initCharts);
  } else {
    initCharts();
  }
})();