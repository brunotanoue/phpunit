pipeline {
    options {
        disableConcurrentBuilds()
    }

    agent any

    environment {
        BUILD_URL = "${BUILD_URL}"
    }

    stages {
        stage ('Checkout') {
          steps {
            checkout scm
          }
        }
        stage('Configurando dependências por pastas') {
          steps {
            sh 'composer install --no-scripts'
          }
        }
        stage('Build') {
            steps {
                echo 'Building project...'
            }
        }
        stage('Test') {
          steps {
            script  {
              try {
                sh './phpunit --log-junit reports/report.xml --coverage-clover reports/coverage/clover.xml --coverage-html reports/coverage'
              } catch (err) {
                  echo "Identificado: ${err}"
                  currentBuild.result = 'failure'
              }
            }
          }
        }
        stage('Junit Report') {
          steps {
            junit "report.xml"
          }
        }
        stage('Clover') {
            steps{
                step([$class: 'CloverPublisher', cloverReportDir: 'reports/coverage/', cloverReportFileName: 'clover.xml'])
            }
        }
        stage('Html Publish') {
            steps{
                 publishHTML (target: [
                    allowMissing: true,
                    alwaysLinkToLastBuild: false,
                    keepAll: true,
                    reportDir: 'reports/coverage',
                    reportFiles: 'index.html',
                    reportName: "Coverage Report"
            ])}
        }
        stage("Extract test results") {
            steps{
              step([$class: 'CoberturaPublisher', autoUpdateHealth: false, autoUpdateStability: false, coberturaReportFile: 'reports/coverage/clover.xml', failUnhealthy: false, failUnstable: false, maxNumberOfBuilds: 0, onlyStable: false, sourceEncoding: 'ASCII', zoomCoverageChart: false])
            }
        }
        stage('Deploy') {
            steps {
                echo 'Deploy project...'
            }
        }
    }
    post {
        always {
            echo BUILD_URL
            influxDbPublisher(selectedTarget: 'TestDB', customData: assignURL(BUILD_URL))
        }
    }
}

def assignURL(build_url) {
    def buildURL = [:]
    buildURL['url'] = build_url
    return buildURL
}
