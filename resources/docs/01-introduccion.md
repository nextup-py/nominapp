# Introducción al Sistema

Bienvenido al sistema de Gestión de Recursos Humanos. Esta guía explica cómo usar cada módulo del panel administrativo.

## ¿Qué puede hacer el sistema?

El sistema cubre el ciclo completo de gestión del personal:

- **Organización:** estructura jerárquica de empresa, sucursales, departamentos y cargos
- **Empleados y Contratos:** registro completo, ciclo de vida del contrato y reportes de plantilla
- **Horarios y Asistencias:** horarios fijos, patrones de rotación, planificador visual de turnos y marcaciones por reconocimiento facial (terminal compartida o dispositivo personal)
- **Vacaciones, Ausencias y Licencias:** saldo de días, solicitudes, y justificación automática de inasistencias
- **Nóminas:** liquidación mensual/quincenal/semanal con percepciones, deducciones y recibos en PDF
- **Créditos:** préstamos, adelantos de salario y retiros de mercadería con descuento automático en nómina
- **Aguinaldo y Liquidaciones:** cálculo del 13.° salario y finiquito al término de la relación laboral
- **Amonestaciones:** registro documental de sanciones disciplinarias
- **Reportes:** vistas agregadas exportables a PDF/Excel de salarios, contratos, empleados, vacaciones y préstamos
- **Configuración:** usuarios, roles y permisos, feriados y parámetros del sistema

## Jerarquía de datos

Dos ejes independientes que se unen en el Contrato:

```
Empresa → Sucursal → Empleado
Empresa → Departamento → Cargo
```

El empleado pertenece a una **Sucursal**. Su salario, cargo y fecha de ingreso están en el **Contrato activo**, no en el perfil del empleado directamente.

> **Importante:** Siempre use el contrato activo como fuente de verdad del cargo y salario. El campo de cargo en el perfil del empleado es un campo histórico.

## Flujo de trabajo inicial recomendado

1. Crear los **usuarios** del sistema y asignarles un **rol** (Super Admin, RRHH, Contador/Nómina o Solo Lectura) — sin rol, un usuario no puede entrar al panel
2. Crear la **empresa** con sus datos legales y logo
3. Crear las **sucursales** de la empresa
4. Definir **departamentos** y **cargos** (con jerarquía si corresponde)
5. Configurar los **horarios** de trabajo con días y descansos (o un **patrón de rotación** si el turno rota)
6. Registrar los **empleados** y crear sus **contratos** iniciales
7. Asignar un **horario** o **patrón de rotación** a cada empleado
8. Cargar los **feriados** del año
9. Habilitar las **marcaciones** (terminal o dispositivo personal con reconocimiento facial)
10. Cada período: generar **nómina**, revisar y aprobar
11. Gestionar **vacaciones**, **ausencias**, **licencias** y **préstamos** según necesidad
12. Al cierre de año: calcular y emitir el **aguinaldo**

Ver el capítulo **Roles y Permisos** para el detalle de qué puede hacer cada rol.
