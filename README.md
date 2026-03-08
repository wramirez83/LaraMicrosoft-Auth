# LaraMicrosoft-Auth

Librería PHP para autenticación social con **Microsoft Entra ID** (Office 365) mediante OAuth 2.0. Pensada para backends en PHP (Laravel, Slim, etc.) que exponen la lógica de login; el frontend (Vue, Nuxt, React) solo redirige al usuario a Microsoft y envía el código de autorización de vuelta al backend.

- **PHP 8.4+**
- **Flujo:** Authorization Code (backend confidencial)
- **Frontend:** Cualquier SPA o SSR (Vue, Nuxt, React, etc.)

---

## Índice

- [Requisitos](#requisitos)
- [Instalación](#instalación)
- [Flujo de autenticación](#flujo-de-autenticación)
- [Registro en Azure (Entra ID)](#registro-en-azure-entra-id)
- [Configuración en el backend](#configuración-en-el-backend)
- [Endpoints del backend](#endpoints-del-backend)
- [Integración en el frontend](#integración-en-el-frontend)
- [Referencia de la API](#referencia-de-la-api)
- [Opciones de configuración](#opciones-de-configuración)
- [Solución de problemas](#solución-de-problemas)
- [Seguridad](#seguridad)
- [Licencia](#licencia)

---

## Requisitos

- **PHP** >= 8.4
- **Composer** 2.x
- **Aplicación registrada** en [Azure Portal](https://portal.azure.com) (Microsoft Entra ID) con Client ID, Client Secret y URI de redirección configurados

---

## Instalación

```bash
composer require laramicrosoft/auth
```

---

## Flujo de autenticación

```
┌─────────────┐     GET /auth/entra/url      ┌─────────────┐
│  Frontend   │ ──────────────────────────► │   Backend    │
│ (Vue/React) │ ◄──────────────────────────  │    (PHP)     │
└──────┬──────┘     { url, state }          └──────┬──────┘
       │                                            │
       │  redirect usuario a url                     │ guarda state en sesión
       ▼                                            │
┌─────────────┐                                     │
│  Microsoft  │  usuario inicia sesión             │
│  Entra ID   │  y acepta permisos                  │
└──────┬──────┘                                     │
       │ redirect a redirect_uri?code=...&state=... │
       ▼                                            │
┌─────────────┐     POST /auth/entra/callback       │
│  Frontend   │  { code, state } ─────────────────►│
│  (callback) │ ◄───────────────────────────────────│  valida state, canjea
└─────────────┘     sesión / token / cookie         │  code por token y usuario
```

1. El frontend pide al backend la **URL de autorización** (y opcionalmente un `state`).
2. El backend genera la URL de Microsoft, guarda el `state` en sesión y devuelve `{ url, state }`.
3. El frontend redirige al usuario a esa URL; el usuario inicia sesión en Microsoft.
4. Microsoft redirige a tu `redirect_uri` con `code` y `state` en la query.
5. El frontend envía `code` y `state` al backend; el backend valida el `state`, canjea el `code` por tokens y usuario, y establece la sesión (o devuelve un token).

---

## Registro en Azure (Entra ID)

### Pasos

1. Entra en [Azure Portal](https://portal.azure.com) → **Microsoft Entra ID** → **Registros de aplicaciones** → **Nuevo registro**.

2. **Nombre**: por ejemplo `Mi App` o `LaraMicrosoft-Auth Demo`.

3. **Tipos de cuenta admitidos**:
   - **Solo mi organización**: solo usuarios de tu tenant.
   - **Cualquier organización**: cuentas laborales o escolares de cualquier tenant.
   - **Cuentas personales y laborales**: incluye cuentas Microsoft personales (outlook.com, etc.).

4. **URI de redirección**:
   - Tipo **Web**.
   - URL donde Microsoft enviará al usuario tras el login. Debe coincidir exactamente con la ruta de callback de tu app (backend o frontend), por ejemplo:
     - Producción: `https://tu-dominio.com/auth/entra/callback`
     - Local: `http://localhost:3000/auth/callback` (ajusta puerto y ruta a tu app).

5. Tras crear el registro, anota:
   - **Id. de aplicación (cliente)** → lo usarás como `client_id`.
   - **Id. de directorio (inquilino)** → lo usarás como `tenant` (o `common` para multi-tenant).

6. **Certificados y secretos** → **Nuevo secreto de cliente** → copia el **Valor** (solo se muestra una vez) → `client_secret`.

7. **Permisos de API** → **Agregar un permiso** → **Microsoft Graph** → **Permisos delegados**. Añade al menos:
   - `openid`
   - `profile`
   - `email`
   - `User.Read`

### Resumen de valores

| Parámetro      | Dónde se obtiene                          |
|----------------|--------------------------------------------|
| `client_id`    | Registro de la aplicación → Información esencial |
| `client_secret`| Certificados y secretos → Valor del secreto |
| `tenant`       | Información esencial → Id. de directorio, o `common` |
| `redirect_uri` | La URL que configuraste en URI de redirección |

---

## Configuración en el backend

Crea la configuración a partir de un array (por ejemplo variables de entorno):

```php
use LaraMicrosoft\Auth\Config\EntraIdConfig;
use LaraMicrosoft\Auth\EntraIdAuthService;

$config = EntraIdConfig::fromArray([
    'client_id'     => getenv('ENTRA_CLIENT_ID'),
    'client_secret' => getenv('ENTRA_CLIENT_SECRET'),
    'redirect_uri'  => getenv('ENTRA_REDIRECT_URI'),  // ej. https://tu-dominio.com/auth/entra/callback
    'tenant'        => getenv('ENTRA_TENANT_ID') ?: 'common',
    'scopes'        => ['openid', 'profile', 'email', 'User.Read'],
]);

$entraAuth = new EntraIdAuthService($config);
```

Ejemplo de `.env`:

```env
ENTRA_CLIENT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
ENTRA_CLIENT_SECRET=tu~secreto~de~cliente
ENTRA_REDIRECT_URI=https://tu-dominio.com/auth/entra/callback
ENTRA_TENANT_ID=common
```

La librería exige que `client_id`, `client_secret` y `redirect_uri` no estén vacíos; si lo están, se lanzará una excepción al crear la config.

---

## Endpoints del backend

Tu aplicación debe exponer al menos dos rutas que usen `EntraIdAuthService`.

### 1. Obtener URL de login

El frontend llama a esta ruta para obtener la URL a la que redirigir al usuario.

**Ejemplo (pseudo-framework):**

```php
// GET /api/auth/entra/url

$state = bin2hex(random_bytes(16));
$_SESSION['entra_oauth_state'] = $state;  // o Redis, etc.

$result = $entraAuth->getAuthorizationUrl($state);

return [
    'url'   => $result['url'],
    'state' => $result['state'],
];
```

El frontend guarda `state` (por ejemplo en `sessionStorage`) y redirige al usuario a `url`.

### 2. Callback: canjear código por token y usuario

Microsoft redirige al usuario a tu `redirect_uri` con `?code=...&state=...`. El frontend (o el backend si el callback es una ruta del propio backend) debe enviar `code` y `state` a esta ruta.

**Ejemplo (pseudo-framework):**

```php
// POST /api/auth/entra/callback
// Body: { "code": "...", "state": "..." }

$code  = $request->input('code');
$state = $request->input('state');
$expectedState = $_SESSION['entra_oauth_state'] ?? null;

try {
    $result = $entraAuth->exchangeCodeAndGetUser($code, $state, $expectedState);
} catch (\LaraMicrosoft\Auth\Exception\InvalidStateException $e) {
    // State no coincide: posible CSRF
    return response()->json(['error' => 'invalid_state'], 400);
} catch (\LaraMicrosoft\Auth\Exception\TokenExchangeException $e) {
    // Error al canjear el código (código expirado, revocado, etc.)
    return response()->json(['error' => 'token_exchange_failed'], 400);
}

$accessToken = $result['token'];
$user        = $result['user'];

// Ejemplo de datos del usuario
$user->getId();       // sub/oid de Microsoft
$user->getEmail();    // email o preferred_username
$user->getName();     // nombre completo
$user->getGivenName();
$user->getFamilyName();
$user->toArray();     // todos los claims

// Aquí: crear o actualizar usuario en tu BD, iniciar sesión, devolver cookie o JWT al frontend
```

---

## Integración en el frontend

### Vue 3 o React (fetch)

**Iniciar login:**

```javascript
async function loginWithEntra() {
  const res = await fetch('/api/auth/entra/url', { credentials: 'include' });
  const { url, state } = await res.json();
  sessionStorage.setItem('entra_oauth_state', state);
  window.location.href = url;
}
```

**Página de callback** (ruta que hayas configurado como `redirect_uri` en Azure):

```javascript
const params = new URLSearchParams(window.location.search);
const code = params.get('code');
const state = params.get('state');

if (code && state) {
  const res = await fetch('/api/auth/entra/callback', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    body: JSON.stringify({ code, state }),
  });
  if (res.ok) {
    // Backend habrá establecido cookie/sesión; redirigir al dashboard
    window.location.href = '/dashboard';
  }
}
```

### Nuxt 3

**Página de login** (`pages/login.vue`):

```vue
<script setup>
async function loginWithEntra() {
  const { data } = await useFetch('/api/auth/entra/url');
  if (data.value?.url) {
    sessionStorage.setItem('entra_oauth_state', data.value.state);
    await navigateTo(data.value.url, { external: true });
  }
}
</script>

<template>
  <button @click="loginWithEntra">Iniciar sesión con Office 365</button>
</template>
```

**Página de callback** (`pages/auth/callback.vue`): la URL de esta página debe ser exactamente la configurada como `redirect_uri` en Azure.

```vue
<script setup>
const route = useRoute();
const code = route.query.code;
const state = route.query.state;

if (code && state) {
  await $fetch('/api/auth/entra/callback', {
    method: 'POST',
    body: { code, state },
  });
  await navigateTo('/');
}
</script>
```

---

## Referencia de la API

| Método | Descripción |
|--------|-------------|
| `getAuthorizationUrl(?string $state = null)` | Devuelve `['url' => string, 'state' => string]`. Si no pasas `state`, se genera uno aleatorio. |
| `exchangeCodeForToken(string $code, string $state, ?string $expectedState)` | Canjea el código por un `AccessToken`. Lanza `InvalidStateException` o `TokenExchangeException` si falla. |
| `getUser(AccessToken $token)` | Obtiene el usuario desde Microsoft (userinfo) como `EntraIdResourceOwner`. |
| `exchangeCodeAndGetUser(string $code, string $state, ?string $expectedState)` | Equivalente a `exchangeCodeForToken` + `getUser`; devuelve `['token' => AccessToken, 'user' => EntraIdResourceOwner]`. |
| `getConfig()` | Devuelve la instancia de `EntraIdConfig` usada. |

### EntraIdResourceOwner (usuario)

- `getId()` – identificador (sub/oid)
- `getEmail()` – email o preferred_username
- `getName()` – nombre completo
- `getGivenName()` – nombre
- `getFamilyName()` – apellido
- `toArray()` – array con todos los claims

---

## Opciones de configuración

| Clave | Tipo | Requerido | Descripción |
|-------|------|-----------|-------------|
| `client_id` | string | Sí | Application (client) ID de Azure. |
| `client_secret` | string | Sí | Valor del secreto de cliente. |
| `redirect_uri` | string | Sí | URI de redirección registrada en Azure. |
| `tenant` | string | No | `common`, `organizations`, `consumers` o ID del tenant. Por defecto: `common`. |
| `scopes` | string[] | No | Scopes OAuth; por defecto: `['openid', 'profile', 'email', 'User.Read']`. |
| `prompt` | string | No | Comportamiento de login: `login`, `none`, `consent`, `select_account`. |

---

## Solución de problemas

### AADSTS900144 — "The request body must contain the following parameter: 'client_id'"

Significa que la petición al endpoint de tokens de Microsoft no incluye `client_id`.

- Comprueba que en tu `.env` (o config) tengas **ENTRA_CLIENT_ID** con el valor del **Id. de aplicación (cliente)** del registro en Azure.
- Asegúrate de que la app lee esa variable al construir `EntraIdConfig` y que no esté vacía. Si está vacía, la librería lanzará una excepción al crear la config.

### AADSTS50011 — "Reply URL does not match"

La `redirect_uri` que envías no coincide con ninguna de las URLs configuradas en el registro de la aplicación.

- En Azure → Registro de la aplicación → Autenticación → URI de redirección, añade exactamente la misma URL que usas en `redirect_uri` (incluyendo protocolo, dominio, puerto y path).

### Invalid state / Estado inválido

- El `state` que envías en el callback debe ser el mismo que guardaste al generar la URL (por ejemplo en sesión).
- Asegúrate de que el frontend envía el mismo `state` que recibió al pedir la URL y de que el backend compara con el guardado (por sesión o almacén equivalente).

### Código ya canjeado o expirado

Los códigos de autorización son de un solo uso y caducan en poco tiempo (aprox. 1 minuto). Si el usuario recarga la página de callback o se envía el mismo código dos veces, Microsoft devolverá error; en ese caso hay que pedir de nuevo la URL de login e iniciar el flujo otra vez.

---

## Seguridad

- **State:** Siempre genera y valida un `state` aleatorio para evitar ataques CSRF. Guarda el `state` en sesión (o almacén vinculado al usuario) al generar la URL y compáralo en el callback.
- **Client secret:** No expongas nunca el `client_secret` en el frontend; úsalo solo en el backend.
- **HTTPS:** En producción usa siempre HTTPS para `redirect_uri` y para las rutas de tu API.
- **Redirect URI:** Registra solo las URLs que realmente uses; evita wildcards si no son necesarios.

---

## Licencia

MIT.
