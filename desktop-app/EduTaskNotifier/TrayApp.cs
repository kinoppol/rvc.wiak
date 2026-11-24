using System.Drawing.Drawing2D;
using System.Runtime.InteropServices;

namespace EduTaskNotifier;

internal sealed class TrayApp : ApplicationContext
{
    private const int MaxRecent = 5;
    private static readonly TimeSpan MaxBackoff = TimeSpan.FromMinutes(5);
    private static readonly TimeSpan UpdateEvery = TimeSpan.FromHours(6);

    private readonly string _server = AppSettings.Server();
    private readonly AppSettings _settings = AppSettings.Load();
    private readonly ApiClient _api;
    private readonly Updater _updater;
    private readonly NotifyIcon _tray;
    private readonly ContextMenuStrip _menu = new();
    private readonly SemaphoreSlim _wake = new(0);
    private readonly CancellationTokenSource _stop = new();
    private readonly List<FeedItem> _recent = [];

    private string? _token;
    private bool _pairing;
    private bool _offline;
    private int _unread;
    private FeedSummary _summary = new(0, 0, 0);
    private DateTime _nextUpdateCheck = DateTime.MinValue;

    public TrayApp()
    {
        _api = new ApiClient(_server);
        _updater = new Updater(_server);
        _token = TokenStore.Read(_server);
        _tray = new NotifyIcon { ContextMenuStrip = _menu, Visible = true };
        _tray.MouseClick += (_, e) =>
        {
            if (e.Button == MouseButtons.Left)
            {
                if (_token is null) { _ = PairAsync(); } else { Pairing.OpenBrowser(_server + "/"); }
            }
        };
        _menu.Opening += (_, _) => BuildMenu();
        RefreshTray();
        _ = LoopAsync();
        if (_token is null)
        {
            _ = PairAsync();
        }
    }

    private async Task LoopAsync()
    {
        var delay = TimeSpan.FromSeconds(60);
        var failures = 0;
        while (!_stop.IsCancellationRequested)
        {
            if (_token is not null)
            {
                try
                {
                    delay = await PollAllAsync();
                    failures = 0;
                    SetOffline(false);
                }
                catch (InvalidTokenException)
                {
                    Disconnect();
                }
                catch (Exception e) when (e is HttpRequestException or TaskCanceledException or System.Text.Json.JsonException)
                {
                    if (_stop.IsCancellationRequested) { return; }
                    failures++;
                    delay = TimeSpan.FromSeconds(Math.Min(MaxBackoff.TotalSeconds, 30 * Math.Pow(2, failures - 1)));
                    SetOffline(true);
                }
            }
            await CheckUpdatesAsync();
            try
            {
                await _wake.WaitAsync(delay, _stop.Token);
            }
            catch (OperationCanceledException)
            {
                return;
            }
        }
    }

    private async Task<TimeSpan> PollAllAsync()
    {
        var fresh = new List<FeedItem>();
        FeedResponse page;
        var cursor = _settings.Cursor;
        do
        {
            page = await _api.PollAsync(_token!, cursor, _stop.Token);
            fresh.AddRange(page.Items);
            cursor = page.Cursor;
        } while (page.HasMore);

        _unread = page.Unread;
        _summary = page.Summary;
        if (fresh.Count > 0)
        {
            _recent.InsertRange(0, Enumerable.Reverse(fresh));
            _recent.RemoveRange(Math.Min(MaxRecent, _recent.Count), Math.Max(0, _recent.Count - MaxRecent));
            try
            {
                Toasts.ShowFeed(fresh, _server);
            }
            catch (Exception e) when (e is COMException or InvalidOperationException or UnauthorizedAccessException)
            {
                // Windows refused the toast; the items are still in the tray menu.
            }
        }
        // Advanced only after the toasts, so a crash in between repeats rather than loses them.
        _settings.Cursor = cursor;
        _settings.UserName = page.User.FullName;
        _settings.Save();
        RefreshTray();
        return TimeSpan.FromSeconds(Math.Clamp(page.PollSeconds, 15, 600));
    }

    private async Task PairAsync()
    {
        if (_pairing)
        {
            return;
        }
        _pairing = true;
        RefreshTray();
        try
        {
            var result = await Pairing.RunAsync(_api, _server, _stop.Token);
            if (result is not null)
            {
                TokenStore.Write(_server, result.Token);
                _token = result.Token;
                _settings.Cursor = result.Cursor;
                _settings.UserName = result.User.FullName;
                _settings.Save();
                _recent.Clear();
                Toasts.Show("เชื่อมต่อสำเร็จ", $"พร้อมรับแจ้งเตือนของ {result.User.FullName}", new Uri(_server + "/"), "paired");
                _wake.Release();
            }
        }
        catch (OperationCanceledException)
        {
            // Timed out or closing.
        }
        catch (Exception e) when (e is HttpRequestException or IOException or System.Net.Sockets.SocketException or InvalidTokenException or System.ComponentModel.Win32Exception)
        {
            _tray.ShowBalloonTip(5000, "EduTask", "เชื่อมต่อไม่สำเร็จ กรุณาลองใหม่", ToolTipIcon.Warning);
        }
        finally
        {
            _pairing = false;
            RefreshTray();
        }
    }

    private async Task CheckUpdatesAsync()
    {
        if (DateTime.UtcNow < _nextUpdateCheck || !_updater.IsInstalled)
        {
            return;
        }
        _nextUpdateCheck = DateTime.UtcNow + UpdateEvery;
        try
        {
            if (await _updater.CheckAndDownloadAsync())
            {
                Toasts.Show("มีแอปรุ่นใหม่", $"รุ่น {_updater.ReadyVersion} จะติดตั้งเมื่อปิดหรือรีสตาร์ตแอป", new Uri(_server + "/desktop/download"), "update");
                RefreshTray();
            }
        }
        catch (Exception e) when (e is not OutOfMemoryException)
        {
            // Retry at the next interval; a failed update must not stop notifications.
            _nextUpdateCheck = DateTime.UtcNow + TimeSpan.FromHours(1);
        }
    }

    private void Disconnect()
    {
        try
        {
            TokenStore.Delete(_server);
        }
        catch (System.ComponentModel.Win32Exception)
        {
            // The server has already refused or revoked this token.
        }
        _token = null;
        _settings.Cursor = 0;
        _settings.UserName = null;
        _settings.Save();
        _unread = 0;
        _summary = new FeedSummary(0, 0, 0);
        _recent.Clear();
        SetOffline(false);
        RefreshTray();
    }

    private async Task SignOutAsync()
    {
        var confirm = MessageBox.Show("ยกเลิกการเชื่อมต่อเครื่องนี้และหยุดรับแจ้งเตือน?", "EduTask Notifier",
            MessageBoxButtons.YesNo, MessageBoxIcon.Question, MessageBoxDefaultButton.Button2);
        if (confirm != DialogResult.Yes || _token is null)
        {
            return;
        }
        var token = _token;
        Disconnect();
        try
        {
            await _api.RevokeAsync(token, _stop.Token);
        }
        catch (Exception e) when (e is HttpRequestException or TaskCanceledException or InvalidTokenException)
        {
            _tray.ShowBalloonTip(5000, "EduTask", "ออกจากเครื่องนี้แล้ว แต่แจ้งเซิร์ฟเวอร์ไม่สำเร็จ ยกเลิกอุปกรณ์ได้ที่หน้าการแจ้งเตือนของฉัน", ToolTipIcon.Warning);
        }
    }

    private async Task MarkAllReadAsync()
    {
        if (_token is null)
        {
            return;
        }
        try
        {
            await _api.MarkAllReadAsync(_token, _stop.Token);
            _recent.Clear();
            _wake.Release();
        }
        catch (InvalidTokenException)
        {
            Disconnect();
        }
        catch (Exception e) when (e is HttpRequestException or TaskCanceledException)
        {
            SetOffline(true);
        }
    }

    private void BuildMenu()
    {
        foreach (var old in _menu.Items.Cast<ToolStripItem>().ToList())
        {
            old.Dispose();
        }
        _menu.Items.Clear();

        if (_token is null)
        {
            _menu.Items.Add(Item(_pairing ? "กำลังรออนุญาตในเบราว์เซอร์…" : "เชื่อมต่อบัญชี", () => _ = PairAsync(), !_pairing));
        }
        else
        {
            _menu.Items.Add(Item(_settings.UserName ?? "EduTask", () => { }, false));
            _menu.Items.Add(Item(_offline ? "เชื่อมต่อเซิร์ฟเวอร์ไม่ได้" : $"ยังไม่อ่าน {_unread} · ด่วน {_summary.Urgent} · ใกล้กำหนด {_summary.Warning}", () => { }, false));
            _menu.Items.Add(new ToolStripSeparator());
            foreach (var item in _recent)
            {
                var label = $"{item.Heading}: {item.Ticket.Title}";
                var link = Toasts.LinkFor(item, _server).ToString();
                _menu.Items.Add(Item(label.Length > 52 ? label[..52] + "…" : label, () => Pairing.OpenBrowser(link)));
            }
            if (_recent.Count > 0)
            {
                _menu.Items.Add(new ToolStripSeparator());
            }
            _menu.Items.Add(Item("เปิดระบบงาน", () => Pairing.OpenBrowser(_server + "/")));
            _menu.Items.Add(Item("ตรวจสอบตอนนี้", () => _wake.Release()));
            _menu.Items.Add(Item("ทำเครื่องหมายว่าอ่านทั้งหมด", () => _ = MarkAllReadAsync(), _unread > 0));
        }

        _menu.Items.Add(new ToolStripSeparator());
        if (_updater.ReadyVersion is { } version)
        {
            _menu.Items.Add(Item($"รีสตาร์ตเพื่ออัปเดตเป็นรุ่น {version}", RestartIntoUpdate));
        }
        var autostart = new ToolStripMenuItem("เริ่มพร้อม Windows") { Checked = Autostart.IsEnabled() };
        autostart.Click += (_, _) => Autostart.Set(!autostart.Checked);
        _menu.Items.Add(autostart);
        if (_token is not null)
        {
            _menu.Items.Add(Item("ยกเลิกการเชื่อมต่อเครื่องนี้", () => _ = SignOutAsync()));
        }
        _menu.Items.Add(Item($"ออกจากแอป (v{ApiClient.Version})", ExitApp));
    }

    private static ToolStripMenuItem Item(string text, Action action, bool enabled = true)
    {
        var item = new ToolStripMenuItem(text) { Enabled = enabled };
        item.Click += (_, _) => action();
        return item;
    }

    private void SetOffline(bool offline)
    {
        if (_offline != offline)
        {
            _offline = offline;
            RefreshTray();
        }
    }

    private void RefreshTray()
    {
        var text = _token is null ? "EduTask: ยังไม่ได้เชื่อมต่อ"
            : _offline ? "EduTask: เชื่อมต่อเซิร์ฟเวอร์ไม่ได้"
            : $"EduTask: ยังไม่อ่าน {_unread} รายการ";
        _tray.Text = text.Length > 63 ? text[..63] : text;

        var color = _token is null || _offline ? Color.FromArgb(100, 116, 139) : Color.FromArgb(37, 99, 235);
        using var bitmap = new Bitmap(32, 32);
        using (var g = Graphics.FromImage(bitmap))
        {
            g.SmoothingMode = SmoothingMode.AntiAlias;
            using var fill = new SolidBrush(color);
            g.FillEllipse(fill, 1, 1, 30, 30);
            using var pen = new Pen(Color.White, 3.5f) { StartCap = LineCap.Round, EndCap = LineCap.Round, LineJoin = LineJoin.Round };
            g.DrawLines(pen, new[] { new PointF(9, 16), new PointF(14, 21), new PointF(23, 11) });
            if (_token is not null && _unread > 0)
            {
                using var badge = new SolidBrush(Color.FromArgb(220, 38, 38));
                g.FillEllipse(badge, 17, 0, 15, 15);
            }
        }
        var handle = bitmap.GetHicon();
        var previous = _tray.Icon;
        using (var icon = Icon.FromHandle(handle))
        {
            _tray.Icon = (Icon)icon.Clone();
        }
        DestroyIcon(handle);
        previous?.Dispose();
    }

    private void RestartIntoUpdate()
    {
        _tray.Visible = false;
        _updater.RestartIntoUpdate();
    }

    private void ExitApp()
    {
        _stop.Cancel();
        _tray.Visible = false;
        try { _updater.ApplyOnExit(); } catch (Exception e) when (e is not OutOfMemoryException) { /* retried next run */ }
        ExitThread();
    }

    protected override void Dispose(bool disposing)
    {
        if (disposing)
        {
            _tray.Dispose();
            _menu.Dispose();
            _api.Dispose();
            _stop.Dispose();
            _wake.Dispose();
        }
        base.Dispose(disposing);
    }

    [DllImport("user32.dll")]
    private static extern bool DestroyIcon(IntPtr handle);
}
