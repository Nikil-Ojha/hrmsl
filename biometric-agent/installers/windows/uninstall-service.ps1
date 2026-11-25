param(
    [string]$ServiceName = "HRMSBiometricAgent"
)

$nssm = Get-Command nssm -ErrorAction SilentlyContinue
if ($nssm -and (Get-Service -Name $ServiceName -ErrorAction SilentlyContinue)) {
    & $nssm.Source stop $ServiceName
    & $nssm.Source remove $ServiceName confirm
    Write-Host "Windows Service '$ServiceName' removed."
}

if (Get-ScheduledTask -TaskName $ServiceName -ErrorAction SilentlyContinue) {
    Stop-ScheduledTask -TaskName $ServiceName -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $ServiceName -Confirm:$false
    Write-Host "Scheduled Task '$ServiceName' removed."
}
