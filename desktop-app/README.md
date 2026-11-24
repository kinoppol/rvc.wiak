# EduTask Notifier

Windows 11 tray app for the task system: shows toasts for new and due tickets, opens the ticket in the browser when clicked, and updates itself with Velopack.

## Build a release

Needs the .NET 10 SDK.

```powershell
powershell -ExecutionPolicy Bypass -File desktop-app\build-release.ps1 -Version 1.0.1
```

Upload these two files from `desktop-app\releases` at `/admin/desktop-releases`:

- `EduTaskNotifier-win-Setup.exe`
- `EduTaskNotifier-<version>-full.nupkg`

Installed apps check `/desktop/updates` at startup and every 6 hours, download a higher version in the background, and apply it on restart or exit. Setting an older release as current changes the download page, but installed apps never downgrade.

## Develop against another server

Set `EDUTASK_SERVER` to an `https` URL or a loopback `http` URL, including the base path:

```powershell
$env:EDUTASK_SERVER = "http://127.0.0.1:8099/web"
dotnet run --project desktop-app\EduTaskNotifier
```

This folder is named `desktop-app`, not `desktop`, on purpose. Its `.htaccess` denies web access, and a folder called `desktop` would also block the app's `/desktop/*` routes.
