from pathlib import Path

source_dir = Path('/home/ubuntu/projects/ld-tool-770cf429')
project_dir = Path('/home/ubuntu/ld-tool-web')
api_text = (source_dir / 'api3.php').read_text(encoding='utf-8')
chat_text = (source_dir / 'chatgpt.js').read_text(encoding='utf-8')

start_marker = "echo <<<'HTML'"
start = api_text.index(start_marker) + len(start_marker)
end = api_text.index('\nHTML;', start)
html = api_text[start:end]
if html.startswith('\n'):
    html = html[1:]

asset_map = {
    'assets/mimi_ready.png': '/manus-storage/mimi_ready_bdeee600.png',
    'mimi_ready.png': '/manus-storage/mimi_ready_bdeee600.png',
    'mimi_thinking.png': '/manus-storage/mimi_thinking_4d62f863.png',
    'mimi_help.png': '/manus-storage/mimi_help_52a82117.png',
    'mimi_success.png': '/manus-storage/mimi_success_2392d3db.png',
}
for old, new in asset_map.items():
    html = html.replace(old, new)
    chat_text = chat_text.replace(old, new)

html = html.replace('</head>', '<base href="/">\n</head>', 1)
html = html.replace('</body>', '<script>\n' + chat_text + '\n</script>\n</body>', 1)
(project_dir / 'client/src/legacy.html').write_text(html, encoding='utf-8')
(project_dir / 'client/src/legacy-source.js').write_text(chat_text, encoding='utf-8')
(source_dir / 'api3.php').replace(project_dir / 'api3.php') if False else None
