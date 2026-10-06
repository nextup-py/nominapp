<?php

namespace App\Exceptions;

use RuntimeException;

/** Error de negocio al aprobar o rechazar una solicitud de vinculación (el mensaje es apto para mostrar al admin). */
class TerminalPairingException extends RuntimeException {}
