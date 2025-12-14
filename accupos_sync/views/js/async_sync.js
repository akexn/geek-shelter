/**
 * AccuPOS Sync - Синхронизация (прямой запуск)
 */

document.addEventListener('DOMContentLoaded', function() {
    const syncButton = document.getElementById('accupos-manual-sync-btn');
    const statusDiv = document.getElementById('accupos-sync-status');
    
    if (syncButton) {
        syncButton.addEventListener('click', function(e) {
            e.preventDefault();
            startSync();
        });
    }
});

function startSync() {
    const button = document.getElementById('accupos-manual-sync-btn');
    const statusDiv = document.getElementById('accupos-sync-status');
    
    button.disabled = true;
    button.innerHTML = '⏳ Синхронизация выполняется...';
    
    if (statusDiv) {
        statusDiv.innerHTML = '<div class="alert alert-info">⏳ Пожалуйста, подождите. Синхронизация может занять несколько минут...</div>';
    }

    const ajaxUrl = '/modules/accupos_sync/ajax_sync.php';

    fetch(ajaxUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=StartAsyncSync'
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('HTTP ' + response.status + ': ' + response.statusText);
        }
        return response.json();
    })
    .then(data => {
        console.log('✅ Результат синхронизации:', data);
        
        if (data.success && data.status === 'completed') {
            showSyncResult(data.result, button);
        } else if (data.success) {
            showError('Ошибка: неожиданный ответ сервера');
            button.disabled = false;
            button.innerHTML = 'Принудительная синхронизация';
        } else {
            showError(data.message || 'Ошибка синхронизации');
            button.disabled = false;
            button.innerHTML = 'Принудительная синхронизация';
        }
    })
    .catch(error => {
        console.error('❌ Ошибка:', error);
        showError('Ошибка: ' + error.message);
        button.disabled = false;
        button.innerHTML = 'Принудительная синхронизация';
    });
}

function showSyncResult(result, button) {
    const statusDiv = document.getElementById('accupos-sync-status');
    
    console.log('✅ Результат синхронизации:', result);
    
    let html = '<div class="alert alert-success">';
    html += '<h4>✅ Синхронизация завершена!</h4>';
    html += '<ul>';
    html += '<li><strong>Обработано:</strong> ' + (result.processed || result.total || 0) + '</li>';
    html += '<li><strong>Успешно:</strong> ' + (result.success_count || result.success || 0) + '</li>';
    html += '<li><strong>Ошибок:</strong> ' + (result.error_count || result.errors || 0) + '</li>';
    html += '<li><strong>Пропущено:</strong> ' + (result.skipped_count || result.skipped || 0) + '</li>';
    html += '</ul>';
    html += '<p><strong>Время:</strong> ' + (result.duration || '?') + ' сек</p>';
    html += '</div>';
    
    if (statusDiv) {
        statusDiv.innerHTML = html;
    }
    
    button.disabled = false;
    button.innerHTML = 'Принудительная синхронизация';
    
    // Обновляем страницу через 3 секунды
    setTimeout(() => location.reload(), 3000);
}

function showError(message) {
    const statusDiv = document.getElementById('accupos-sync-status');
    
    console.error('❌ Ошибка:', message);
    
    let html = '<div class="alert alert-danger">';
    html += '<h4>❌ Ошибка синхронизации</h4>';
    html += '<p>' + message + '</p>';
    html += '</div>';
    
    if (statusDiv) {
        statusDiv.innerHTML = html;
    }
}

