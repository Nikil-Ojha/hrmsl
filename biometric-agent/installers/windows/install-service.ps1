<#
.SYNOPSIS
    Installs the HRMS Local Biometric Device Communication Agent on Windows.
.DESCRIPTION
    Provisions the agent (if -ServerUrl, -AgentId and -Token are supplied) and
    registers it as a persistent Windows Service using NSSM if available, or
    falls back to a SYSTEM-level Scheduled Task running at startup with auto-restart.
#>
param(
    [Parameter(Mandatory=$false)][string]$ServerUrl,
    [Parameter(Mandatory=$false)][string]$AgentId,
    [Parameter(Mandatory=$false)][string]$Token,
    [string]$InstallDir = "$env:ProgramFiles\HRMSBiometricAgent",
    [string]$DataDir = "$env:ProgramData\HRMSBiometricAgent",
    [string]$ServiceName = "HRMSBiometricAgent",
    [string]$PythonExe = "python"
)

$ErrorActionPreference = "Stop"

Write-Host "Installing HRMS Biometric Agent to $InstallDir ..."
New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null
New-Item -ItemType Directory -Force -Path $DataDir | Out-Null

$SourceRoot = Resolve-Path "$PSScriptRoot\..\.."
Copy-Item -Recurse -Force "$SourceRoot\hrms_agent" "$InstallDir\hrms_agent"
Copy-Item -Recurse -Force "$SourceRoot\plugins" "$DataDir\plugins"

if ($ServerUrl -and $AgentId -and $Token) {
    Write-Host "Provisioning agent $AgentId against $ServerUrl ..."
    Push-Location $InstallDir
    & $PythonExe -m hrms_agent --data-dir "$DataDir" provision --server "$ServerUrl" --agent-id "$AgentId" --token "$Token"
    if ($LASTEXITCODE -ne 0) { Pop-Location; throw "Provisioning failed." }
    Pop-Location
}

$nssm = Get-Command nssm -ErrorAction SilentlyContinue
if ($nssm) {
    Write-Host "Registering Windows Service '$ServiceName' via NSSM ..."
    & $nssm.Source install $ServiceName $PythonExe "-m hrms_agent --data-dir `"$DataDir`" run"
    & $nssm.Source set $ServiceName AppDirectory $InstallDir
    & $nssm.Source set $ServiceName DisplayName "HRMS Biometric Device Agent"
    & $nssm.Source set $ServiceName Description "Bridges local biometric attendance devices securely to HRMS over HTTPS."
    & $nssm.Source set $ServiceName Start SERVICE_AUTO_START
    & $nssm.Source set $ServiceName AppStdout "$DataDir\logs\service-stdout.log"
    & $nssm.Source set $ServiceName AppStderr "$DataDir\logs\service-stderr.log"
    & $nssm.Source start $ServiceName
    Write-Host "Service '$ServiceName' installed and started."
} else {
    Write-Host "NSSM not found; registering SYSTEM Scheduled Task '$ServiceName' (starts at boot, restarts on failure) ..."
    $action = New-ScheduledTaskAction -Execute $PythonExe -Argument "-m hrms_agent --data-dir `"$DataDir`" run" -WorkingDirectory $InstallDir
    $trigger = New-ScheduledTaskTrigger -AtStartup
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit (New-TimeSpan -Days 0)
    Register-ScheduledTask -TaskName $ServiceName -Action $action -Trigger $trigger -Settings $settings -User "SYSTEM" -RunLevel Highest -Force | Out-Null
    Start-ScheduledTask -TaskName $ServiceName
    Write-Host "Scheduled Task '$ServiceName' installed and started."
}
