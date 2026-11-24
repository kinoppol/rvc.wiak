using Velopack;

namespace EduTaskNotifier;

/// <summary>Downloads new versions in the background and applies them on restart or exit.</summary>
internal sealed class Updater
{
    private readonly UpdateManager _manager;
    private UpdateInfo? _ready;

    public Updater(string server) => _manager = new UpdateManager(server + "/desktop/updates");

    public bool IsInstalled => _manager.IsInstalled;
    public string? ReadyVersion => _ready?.TargetFullRelease.Version.ToString();

    /// <returns>true when a new version has just finished downloading.</returns>
    public async Task<bool> CheckAndDownloadAsync()
    {
        if (!_manager.IsInstalled || _ready is not null)
        {
            return false;
        }
        var info = await _manager.CheckForUpdatesAsync();
        if (info is null)
        {
            return false;
        }
        await _manager.DownloadUpdatesAsync(info);
        _ready = info;
        return true;
    }

    public void RestartIntoUpdate()
    {
        if (_ready is not null)
        {
            _manager.ApplyUpdatesAndRestart(_ready.TargetFullRelease);
        }
    }

    public void ApplyOnExit()
    {
        if (_ready is not null)
        {
            _manager.WaitExitThenApplyUpdates(_ready.TargetFullRelease, silent: true, restart: false);
        }
    }
}
