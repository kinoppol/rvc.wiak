using Microsoft.Toolkit.Uwp.Notifications;

namespace EduTaskNotifier;

internal static class Toasts
{
    private const int MaxIndividual = 3;

    /// <summary>
    /// Protocol activation: Windows opens the link itself, so a toast in Action
    /// Center still works after the app has exited.
    /// </summary>
    public static void Show(string heading, string body, Uri link, string tag)
    {
        new ToastContentBuilder()
            .AddText(heading)
            .AddText(body)
            .SetProtocolActivation(link)
            .Show(t =>
            {
                t.Tag = tag;
                t.Group = "edutask";
            });
    }

    public static void ShowFeed(IReadOnlyList<FeedItem> items, string server)
    {
        if (items.Count > MaxIndividual)
        {
            Show("มีแจ้งเตือนใหม่", $"{items.Count} รายการ คลิกเพื่อเปิดระบบงาน", new Uri(server + "/"), "batch");
            return;
        }
        foreach (var item in items)
        {
            Show(item.Heading, $"{item.Ticket.Code} · {item.Body}", LinkFor(item, server), "n" + item.Id);
        }
    }

    /// <summary>Only a path on our own server is ever opened.</summary>
    public static Uri LinkFor(FeedItem item, string server)
    {
        var path = item.OpenPath;
        var safe = path.StartsWith('/') && !path.StartsWith("//", StringComparison.Ordinal) && !path.Contains('\\');
        return new Uri(server + (safe ? path : "/"));
    }
}
