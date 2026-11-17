# AGENTS.md

Guia para agentes (e pessoas) que alteram este repositório.

## Regras

### Comentários no código

- Descreva o estado atual, não o histórico. O comentário diz o que o código faz e por quê agora — nunca o que era antes, o que mudou ou por que deixou de ser assim. Histórico é responsabilidade do git.

### Arquivos temporários

- Não criar diretórios em runtime. Não use `mkdir()` em código de produção. Pastas necessárias devem existir previamente no projeto/deploy.

### Fim de linha

- Todo arquivo de texto usa **LF** (`.gitattributes` com `eol=lf` e `.editorconfig`). Não grave CRLF.

## Sobre o projeto

HPanel é um painel leve para gerenciar vários servidores e sites da Hostinger usando o JWT da sessão do próprio usuário no hPanel. Conversa só com as **APIs internas (não oficiais)** do hPanel — elas podem mudar sem aviso.

### Stack

- PHP 8.1+ sem framework e sem dependências de produção (`composer.json` só tem PHPUnit em dev).
- JS vanilla em módulos ES, **sem build**. CSS único em `assets/css/app.css` (tokens claro/escuro).
- Interface e mensagens em **português do Brasil**.

### Estrutura

| Caminho | Papel |
|---|---|
| `index.php`, `connect.php`, `server.php`, `settings.php` | Páginas finas: escolhem a view em `partials/pages/` |
| `api/*.php` | Endpoints JSON via `Http::handle([...métodos], fn(Request, Context))` |
| `lib/HostingerSource.php` | **Único** lugar que conhece URLs/headers da Hostinger; normaliza as respostas |
| `lib/DataSource.php` | Interface implementada por `HostingerSource` e `DemoSource` |
| `lib/DemoSource.php` + `lib/demo/fixtures.json` | Modo demonstração: dados fictícios (domínios `.example`), mutações → `forbidden` |
| `lib/Catalog.php` | Servidores/sites da sessão e checagem de posse: `username`/domínio vêm daqui, nunca do cliente |
| `lib/Validate.php` | Validação de toda entrada (lança `ApiError::invalid`) |
| `assets/js/pages/*.js` | Um módulo por página; `server.js` monta as abas do servidor |
| `tools/dev-router.php` | Emula o `.htaccess` no `php -S` |
| `extension/` | Extensão HPanel Conector (MV3): lê o cookie `jwt` do hPanel e o entrega ao painel; baixada via `download-extension` |
| `assets/js/extension.js` | Ponte página ↔ extensão (postMessage); o token nunca passa pela página |

### Convenções

- **Recurso novo da Hostinger**: método em `DataSource` → implementação em `HostingerSource` (normalizada, com teste usando `Tests\Support\FakeTransport`) e em `DemoSource` (fixture ou `self::blocked()` para mutações) → endpoint em `api/` → UI.
- **Endpoints**: envelope `{ok, data}` / `{ok:false, code, message}`; mutações só `POST` (CSRF: header `X-HPanel: 1` + mesma origem); `GET` nunca altera estado. Erro da Hostinger vira `upstream_unavailable` ou `session_expired`, sem repassar corpo.
- **URLs sem `.php`**: links e chamadas usam `connect`, `server?orderId=…`, `api/<nome>` (o `api.js` já monta assim).
- **Front**: DOM só via `h()` de `assets/js/h.js` (escapa texto); nada de `innerHTML` com dado externo, script/estilo inline ou handler inline (CSP restrita). Ícones no sprite `assets/icons.svg`. Leituras com cache usam `cached()` de `api.js` (stale-while-revalidate em `sessionStorage`).
- **Assets**: PHP referencia arquivos de `assets/` só via `View::asset()` (gera `assets/v/<versão>/…`, que muda a cada deploy que altera um asset). Em JS, `import` e outros caminhos de asset são sempre relativos ao módulo (`./x.js`, `new URL('../icons.svg', import.meta.url)`), nunca absolutos, para herdar a versão — senão um navegador com cache antigo mistura módulos de deploys diferentes.
- **Testes**: comportamento observável (parsing, validação, fronteiras, demo bloqueado). Não testar texto de implementação nem fiação.
- **Docs**: recurso visível ao usuário atualiza o `README.md` (lista de funcionalidades e “APIs Utilizadas”).
- **Commits**: mensagem curta em português, sem acentos, um recurso por commit.

### Comandos

```bash
composer install
php vendor/phpunit/phpunit/phpunit       # ou vendor/bin/phpunit
node --test tests/js/*.test.mjs          # Node 18+
php -S 127.0.0.1:8099 tools/dev-router.php
```

Smoke de interface: abra o painel e use **Ver demonstração** (sem conta Hostinger).

### Cuidados

- Nunca teste mutações (criar/excluir cron, trocar PHP, chave SSH, Git) na conta real de alguém sem autorização explícita; use o demo e os testes com `FakeTransport`.
- O token JWT é segredo: nunca registre em log, fixture ou mensagem de erro.
- `storage/`, `lib/`, `partials/`, `tests/`, `tools/`, `vendor/`, `docs/`, `extension/` e `config*.php` são bloqueados na web; mantenha `.htaccess`, `tools/dev-router.php` e o bloco Nginx do README em sincronia.
